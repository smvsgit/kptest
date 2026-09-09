<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\XlsxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ScheduledReportController extends Controller
{
    public function index()
    {
        $reports = ScheduledReport::with([
            'recipient:id,name,email,department_id,role,status',
            'runs' => fn ($query) => $query->latest('id')->limit(5),
        ])->latest('id')->get()->map(fn (ScheduledReport $report) => [
            'id' => $report->id,
            'name' => $report->name,
            'report_type' => $report->report_type,
            'frequency' => $report->frequency,
            'format' => $report->format,
            'is_active' => (bool) $report->is_active,
            'recipient_user_id' => $report->recipient_user_id,
            'recipient' => $report->recipient ? ['id' => $report->recipient->id, 'name' => $report->recipient->name, 'email' => $report->recipient->email] : null,
            'department_id' => $report->department_id,
            'last_run_at' => optional($report->last_run_at)->toIso8601String(),
            'next_run_at' => optional($report->next_run_at)->toIso8601String(),
            'runs' => $report->runs->map(fn (ScheduledReportRun $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'row_count' => $run->row_count,
                'error' => $run->error,
                'started_at' => optional($run->started_at)->toIso8601String(),
                'finished_at' => optional($run->finished_at)->toIso8601String(),
                'download_url' => $run->status === 'completed' && $run->file_path ? route('scheduled-report-runs.download', $run) : null,
            ])->values(),
        ])->values();

        return response()->json([
            'enabled' => (bool) (SystemSetting::valueFor('reports.scheduler', ['enabled' => true])['enabled'] ?? true),
            'reports' => $reports,
            'users' => User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email', 'department_id', 'role']),
            'departments' => Department::query()->where('is_active', true)->where('is_system', false)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, AuditService $audit)
    {
        $data = $request->validate($this->rules());
        $recipient = User::findOrFail($data['recipient_user_id']);
        $this->assertRecipientScope($recipient, $data['department_id'] ?? null);
        $report = ScheduledReport::create($data + [
            'created_by' => $request->user()->id,
            'next_run_at' => $this->nextRun($data['frequency']),
        ]);
        $audit->log($request, 'report.schedule.created', $report, 'Scheduled report created.');

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Scheduled report created.', 'id' => $report->id], 201);
        }
        return back()->with('success', 'Scheduled report created.');
    }

    public function update(Request $request, ScheduledReport $scheduledReport, AuditService $audit)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'is_active' => 'sometimes|boolean',
            'report_type' => 'sometimes|in:access,activity,notifications',
            'frequency' => 'sometimes|in:weekly,monthly',
            'format' => 'sometimes|in:csv,xlsx',
            'recipient_user_id' => 'sometimes|exists:users,id',
            'department_id' => 'nullable|exists:departments,id',
            'filters' => 'nullable|array',
        ]);
        $recipient = User::findOrFail($data['recipient_user_id'] ?? $scheduledReport->recipient_user_id);
        $departmentId = array_key_exists('department_id', $data) ? $data['department_id'] : $scheduledReport->department_id;
        $this->assertRecipientScope($recipient, $departmentId);
        if (array_key_exists('frequency', $data) && $data['frequency'] !== $scheduledReport->frequency) {
            $data['next_run_at'] = $this->nextRun($data['frequency']);
        }
        $scheduledReport->update($data);
        $audit->log($request, 'report.schedule.updated', $scheduledReport, 'Scheduled report updated.');

        return $request->expectsJson()
            ? response()->json(['message' => 'Scheduled report updated.'])
            : back()->with('success', 'Scheduled report updated.');
    }

    public function destroy(Request $request, ScheduledReport $scheduledReport, AuditService $audit)
    {
        $audit->log($request, 'report.schedule.deleted', $scheduledReport, 'Scheduled report deleted.');
        $scheduledReport->delete();
        return $request->expectsJson()
            ? response()->json(['message' => 'Scheduled report deleted.'])
            : back()->with('success', 'Scheduled report deleted.');
    }

    public function run(Request $request, ScheduledReport $scheduledReport, AuditService $audit)
    {
        $run = $this->execute($scheduledReport);
        $audit->log($request, 'report.schedule.ran', $scheduledReport, 'Scheduled report run executed.', ['run_id' => $run->id, 'status' => $run->status]);
        $message = $run->status === 'completed'
            ? 'Scheduled report run completed.'
            : 'Scheduled report run failed: '.($run->error ?: 'unknown error');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'status' => $run->status, 'run_id' => $run->id], $run->status === 'completed' ? 200 : 422);
        }
        return back()->with($run->status === 'completed' ? 'success' : 'warning', $message);
    }

    public function download(Request $request, ScheduledReportRun $run)
    {
        $report = $run->scheduledReport ?? ScheduledReport::findOrFail($run->scheduled_report_id);
        abort_unless($request->user()->role === 'super-admin' || $request->user()->id === $report->recipient_user_id, 403);
        abort_unless($run->status === 'completed' && $run->file_path && Storage::disk('local')->exists($run->file_path), 404);
        return Storage::disk('local')->download($run->file_path);
    }

    public function execute(ScheduledReport $report): ScheduledReportRun
    {
        $run = ScheduledReportRun::create(['scheduled_report_id' => $report->id, 'status' => 'running', 'started_at' => now()]);
        try {
            $recipient = $report->recipient()->first();
            abort_unless($recipient && $recipient->isActive(), 422, 'Recipient is no longer active.');
            if ($report->department_id && $recipient->role !== 'super-admin') {
                abort_unless($recipient->department_id === $report->department_id, 403, 'Recipient no longer has department scope.');
            }
            $rows = $this->rows($report, $recipient);
            $dir = 'scheduled-reports/'.date('Y/m');
            Storage::disk('local')->makeDirectory($dir);
            $name = $dir.'/report-'.$report->id.'-'.date('Ymd-His').'.'.$report->format;
            $absolute = Storage::disk('local')->path($name);
            if ($report->format === 'xlsx') {
                app(XlsxService::class)->write(array_keys($rows[0] ?? ['message' => 'No rows']), array_map('array_values', $rows ?: [['message' => 'No rows']]), $absolute);
            } else {
                $handle = fopen($absolute, 'wb');
                abort_unless($handle !== false, 500, 'Could not create scheduled report output.');
                $headers = array_keys($rows[0] ?? ['message' => 'No rows']);
                fputcsv($handle, $headers);
                foreach ($rows as $row) fputcsv($handle, array_values($row));
                fclose($handle);
            }
            $run->update([
                'status' => 'completed',
                'file_path' => $name,
                'checksum_sha256' => hash_file('sha256', $absolute),
                'row_count' => count($rows),
                'finished_at' => now(),
            ]);
            $report->update(['last_run_at' => now(), 'next_run_at' => $this->nextRun($report->frequency)]);
        } catch (\Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => $exception->getMessage(), 'finished_at' => now()]);
        }
        return $run;
    }

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'report_type' => 'required|in:access,activity,notifications',
            'frequency' => 'required|in:weekly,monthly',
            'format' => 'required|in:csv,xlsx',
            'recipient_user_id' => 'required|exists:users,id',
            'department_id' => 'nullable|exists:departments,id',
            'filters' => 'nullable|array',
        ];
    }

    private function assertRecipientScope(User $recipient, ?int $departmentId): void
    {
        if (! $recipient->isActive()) {
            throw ValidationException::withMessages(['recipient_user_id' => 'Recipient must be active.']);
        }
        if ($departmentId && $recipient->role !== 'super-admin' && (int) $recipient->department_id !== (int) $departmentId) {
            throw ValidationException::withMessages(['department_id' => 'Recipient must belong to the selected department or be a Super Admin.']);
        }
    }

    private function nextRun(string $frequency)
    {
        return $frequency === 'weekly' ? now()->addWeek() : now()->addMonth();
    }

    private function rows(ScheduledReport $report, User $user): array
    {
        $query = \App\Models\AuditLog::query()->latest('id')->limit(5000);
        if ($user->role !== 'super-admin') $query->where('actor_user_id', $user->id);
        if ($report->department_id) $query->where('actor_department_id', $report->department_id);
        if ($report->report_type === 'access') $query->where('event', 'like', '%access%');
        if ($report->report_type === 'notifications') $query->where('event', 'like', '%notification%');
        return $query->get(['id', 'event', 'description', 'actor_user_id', 'actor_department_id', 'created_at'])->map(fn ($row) => $row->toArray())->all();
    }
}
