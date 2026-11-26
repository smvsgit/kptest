<?php

namespace App\Mcp;

/**
 * The tool inventory, as MCP clients see it. (Review F06.)
 *
 * One entry per tool: the name a client calls, a description a model can act
 * on, the JSON Schema for its arguments, and the method on PortalTools that
 * does the work. The inventory document handed to the reviewers is this list.
 */
final class ToolRegistry
{
    private static function str(string $description, bool $required = false): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    private static function int(string $description): array
    {
        return ['type' => 'integer', 'description' => $description];
    }

    /** @return array<int,array{name:string,title:string,description:string,method:string,schema:array,writes:bool}> */
    public static function tools(): array
    {
        return [
            // ---------------------------------------- search, list, path lookup
            [
                'name' => 'search_files',
                'title' => 'Search files',
                'description' => 'Search the media library. Returns assets the caller is allowed to see, with their folder path. Filter by text, type, department, category, year, event, person, city, mandir, language, status or folder.',
                'method' => 'searchFiles',
                'writes' => false,
                'schema' => ['type' => 'object', 'properties' => [
                    'query' => self::str('Words to look for in the name or description.'),
                    'type' => ['type' => 'string', 'enum' => ['image', 'video', 'audio', 'document']],
                    'department_id' => self::int('Limit to one department.'),
                    'folder_id' => self::int('Limit to one folder.'),
                    'category_id' => self::int('Limit to one category.'),
                    'subcategory_id' => self::int('Limit to one sub-category.'),
                    'year' => self::int('Business year on the asset.'),
                    'event_id' => self::int('Event / prasang.'),
                    'person_id' => self::int('Guruji / person.'),
                    'city_id' => self::int('City.'),
                    'mandir_id' => self::int('Mandir.'),
                    'language_id' => self::int('Language.'),
                    'access_policy' => ['type' => 'string', 'enum' => ['public', 'protected', 'private']],
                    'asset_status' => self::str('draft, review, approved, published, active, archived.'),
                    'limit' => self::int('How many to return, 1 to 100. Default 25.'),
                ]],
            ],
            [
                'name' => 'list_folders',
                'title' => 'List folders',
                'description' => 'The folder tree of one department, each folder with its id, parent, depth and file count. This is the portal\'s one navigation structure.',
                'method' => 'listFolders',
                'writes' => false,
                'schema' => ['type' => 'object', 'properties' => [
                    'department_id' => self::int('Which department. Defaults to the caller\'s own.'),
                ]],
            ],
            [
                'name' => 'list_folder_files',
                'title' => 'List the files in a folder',
                'description' => 'Every asset directly inside one folder, with its full path.',
                'method' => 'listFolderFiles',
                'writes' => false,
                'schema' => ['type' => 'object', 'required' => ['folder_id'], 'properties' => [
                    'folder_id' => self::int('The folder to look inside.'),
                ]],
            ],
            [
                'name' => 'get_file',
                'title' => 'Get one file',
                'description' => 'One asset in full: metadata, tags, administrator-defined fields, folder path, access policy, current version, and whether this caller may preview or download it.',
                'method' => 'getFile',
                'writes' => false,
                'schema' => ['type' => 'object', 'required' => ['file_id'], 'properties' => [
                    'file_id' => self::int('The asset.'),
                ]],
            ],

            // ------------------------------------------------------- upload
            [
                'name' => 'upload_file',
                'title' => 'Upload a file',
                'description' => 'Upload one file, optionally into a folder. The destination folder decides the owner department. The asset lands as a draft so it can be described afterwards. Files over 25 MB belong in the portal\'s own resumable upload.',
                'method' => 'uploadFile',
                'writes' => true,
                'schema' => ['type' => 'object', 'required' => ['filename', 'content_base64'], 'properties' => [
                    'filename' => self::str('Name including its extension, which decides the media type.'),
                    'content_base64' => self::str('The file itself, base64 encoded.'),
                    'folder_id' => self::int('Where it should land. Without one it goes to the department root.'),
                    'category_id' => self::int('Optional category.'),
                    'access_policy' => ['type' => 'string', 'enum' => ['public', 'protected', 'private']],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tags to set on upload.'],
                ]],
            ],

            // -------------------------------------------- metadata and tags
            [
                'name' => 'update_files',
                'title' => 'Update metadata and tags',
                'description' => 'Set metadata, tags and administrator-defined fields on one asset or several. Only what is sent is changed. A locked custom field may be set once and not changed again.',
                'method' => 'updateFiles',
                'writes' => true,
                'schema' => ['type' => 'object', 'required' => ['file_ids'], 'properties' => [
                    'file_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'The assets to change.'],
                    'metadata' => ['type' => 'object', 'description' => 'Any of year, country_id, state_id, city_id, mandir_id, event_id, person_id, language_id, media_type_id, category_id, subcategory_id, description, internal_remarks.'],
                    'custom_metadata' => ['type' => 'object', 'description' => 'Administrator-defined fields, keyed by the field\'s key.'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'tags_mode' => ['type' => 'string', 'enum' => ['add', 'remove', 'replace'], 'description' => 'Default add.'],
                ]],
            ],

            // ----------------------------------------------- folder actions
            [
                'name' => 'create_folder',
                'title' => 'Create a folder',
                'description' => 'Create a folder, optionally under a parent. Super Admin or Department Admin only.',
                'method' => 'createFolder',
                'writes' => true,
                'schema' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                    'name' => self::str('The folder name.'),
                    'parent_id' => self::int('Create it inside this folder.'),
                    'department_id' => self::int('Which department, when there is no parent.'),
                    'description' => self::str('Optional description.'),
                ]],
            ],
            [
                'name' => 'rename_folder',
                'title' => 'Rename a folder',
                'description' => 'Change a folder\'s name or description. Super Admin or Department Admin only.',
                'method' => 'renameFolder',
                'writes' => true,
                'schema' => ['type' => 'object', 'required' => ['folder_id', 'name'], 'properties' => [
                    'folder_id' => self::int('The folder.'),
                    'name' => self::str('Its new name.'),
                    'description' => self::str('Optional description.'),
                ]],
            ],
            [
                'name' => 'delete_folder',
                'title' => 'Delete a folder',
                'description' => 'Delete an empty folder. Refused while it holds files or sub-folders, and the refusal says how many - including any this caller cannot see.',
                'method' => 'deleteFolder',
                'writes' => true,
                'schema' => ['type' => 'object', 'required' => ['folder_id'], 'properties' => [
                    'folder_id' => self::int('The folder.'),
                ]],
            ],
            [
                'name' => 'move_files',
                'title' => 'Move files',
                'description' => 'Move assets into a folder, or to the department root when no folder is given. Everything moved and the destination must sit in one department.',
                'method' => 'moveFiles',
                'writes' => true,
                'schema' => ['type' => 'object', 'required' => ['file_ids'], 'properties' => [
                    'file_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'folder_id' => self::int('Destination. Omit to move to the department root.'),
                ]],
            ],

            // ------------------------------------------ version replacement
            [
                'name' => 'list_file_versions',
                'title' => 'List versions',
                'description' => 'The version history of one asset: who changed it, when, and why.',
                'method' => 'listVersions',
                'writes' => false,
                'schema' => ['type' => 'object', 'required' => ['file_id'], 'properties' => [
                    'file_id' => self::int('The asset.'),
                ]],
            ],
            [
                'name' => 'add_file_version',
                'title' => 'Replace the file with a new version',
                'description' => 'Replace an asset\'s file while keeping one logical asset: same id, same folder, same permissions. A change note is required and the new file must be the same kind of media.',
                'method' => 'addVersion',
                'writes' => true,
                'schema' => ['type' => 'object', 'required' => ['file_id', 'content_base64', 'change_note'], 'properties' => [
                    'file_id' => self::int('The asset.'),
                    'content_base64' => self::str('The new file, base64 encoded.'),
                    'filename' => self::str('Name of the new file. Defaults to the asset\'s current name.'),
                    'change_note' => self::str('Why it was replaced. At least three characters.'),
                ]],
            ],
            [
                'name' => 'restore_file_version',
                'title' => 'Restore a version',
                'description' => 'Make a historical version current again by adding it as a new version. Nothing is removed from the history.',
                'method' => 'restoreVersion',
                'writes' => true,
                'schema' => ['type' => 'object', 'required' => ['file_id', 'version_id'], 'properties' => [
                    'file_id' => self::int('The asset.'),
                    'version_id' => self::int('The version to bring back.'),
                    'change_note' => self::str('Optional note.'),
                ]],
            ],

            // ------------------------------------------ access and approvals
            [
                'name' => 'request_access',
                'title' => 'Request access',
                'description' => 'Ask for access to a protected asset, with a reason and a level. Only for protected assets, and only from someone who does not already have access.',
                'method' => 'requestAccess',
                'writes' => true,
                'schema' => ['type' => 'object', 'required' => ['file_id', 'reason'], 'properties' => [
                    'file_id' => self::int('The asset.'),
                    'reason' => self::str('Why access is needed. At least five characters.'),
                    'level' => ['type' => 'string', 'enum' => ['view', 'download'], 'description' => 'Default view.'],
                ]],
            ],
            [
                'name' => 'decide_access_request',
                'title' => 'Decide an access request',
                'description' => 'Approve, reject, or ask for more information on a request. Only the owning department\'s reviewer, or an active delegate, may decide.',
                'method' => 'decideAccessRequest',
                'writes' => true,
                'schema' => ['type' => 'object', 'required' => ['request_id', 'status'], 'properties' => [
                    'request_id' => self::int('The request.'),
                    'status' => ['type' => 'string', 'enum' => ['approved', 'rejected', 'more-info']],
                    'note' => self::str('A note for the person who asked.'),
                ]],
            ],
        ];
    }

    public static function find(string $name): ?array
    {
        foreach (self::tools() as $tool) {
            if ($tool['name'] === $name) return $tool;
        }
        return null;
    }
}
