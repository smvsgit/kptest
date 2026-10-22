<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Subcategory;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function store(Request $request, AuditService $audit)
    {
        $request->validate(['name'=>'required|string|max:100|unique:categories,name']);
        $category=Category::create(['name'=>$request->name]);$audit->log($request,'category.created',$category,'Category created.');return back();
    }

    public function update(Request $request, Category $category, AuditService $audit)
    {
        $request->validate(['name'=>['required','string','max:100',Rule::unique('categories','name')->ignore($category->id)]]);
        $previous=$category->name;$category->update(['name'=>$request->name]);
        $audit->log($request,'category.updated',$category,'Category renamed.',['previous_name'=>$previous,'name'=>$category->name]);
        return back();
    }

    public function destroy(Request $request, Category $category, AuditService $audit)
    {
        $audit->log($request,'category.deleted',$category,'Category deleted.',['name'=>$category->name]);$category->delete();return back();
    }

    public function storeSubcategory(Request $request, Category $category, AuditService $audit)
    {
        $request->validate(['name'=>['required','string','max:100',Rule::unique('subcategories','name')->where(fn($q)=>$q->where('category_id',$category->id))]]);
        $sub=Subcategory::create(['category_id'=>$category->id,'name'=>$request->name]);
        $audit->log($request,'subcategory.created',$sub,'Subcategory created.',['category_id'=>$category->id]);
        return back();
    }

    /**
     * Create a subcategory when the parent category is chosen in the form itself
     * (left drawer "Add Sub-Category" flow: pick main category, then type the name).
     */
    public function storeSubcategoryForSelectedParent(Request $request, AuditService $audit)
    {
        $data=$request->validate([
            'category_id'=>'required|integer|exists:categories,id',
            'name'=>['required','string','max:100',Rule::unique('subcategories','name')->where(fn($q)=>$q->where('category_id',$request->integer('category_id')))],
        ]);
        $sub=Subcategory::create(['category_id'=>$data['category_id'],'name'=>$data['name']]);
        $audit->log($request,'subcategory.created',$sub,'Subcategory created.',['category_id'=>$data['category_id']]);
        return back();
    }

    public function updateSubcategory(Request $request, Subcategory $subcategory, AuditService $audit)
    {
        $data=$request->validate([
            'category_id'=>'sometimes|integer|exists:categories,id',
            'name'=>['required','string','max:100',Rule::unique('subcategories','name')
                ->where(fn($q)=>$q->where('category_id',$request->integer('category_id') ?: $subcategory->category_id))
                ->ignore($subcategory->id)],
        ]);
        $previous=['name'=>$subcategory->name,'category_id'=>$subcategory->category_id];
        $subcategory->update(['name'=>$data['name'],'category_id'=>$data['category_id'] ?? $subcategory->category_id]);
        $audit->log($request,'subcategory.updated',$subcategory,'Subcategory updated.',['previous'=>$previous,'name'=>$subcategory->name,'category_id'=>$subcategory->category_id]);
        return back();
    }

    public function destroySubcategory(Request $request, Subcategory $subcategory, AuditService $audit)
    {
        $audit->log($request,'subcategory.deleted',$subcategory,'Subcategory deleted.',['name'=>$subcategory->name]);$subcategory->delete();return back();
    }
}
