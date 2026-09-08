<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Subcategory;
use App\Services\AuditService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function store(Request $request, AuditService $audit)
    {
        $request->validate(['name'=>'required|string|max:100|unique:categories,name']);
        $category=Category::create(['name'=>$request->name]);$audit->log($request,'category.created',$category,'Category created.');return back();
    }
    public function destroy(Request $request, Category $category, AuditService $audit)
    {
        $audit->log($request,'category.deleted',$category,'Category deleted.',['name'=>$category->name]);$category->delete();return back();
    }
    public function storeSubcategory(Request $request, Category $category, AuditService $audit)
    {
        $request->validate(['name'=>'required|string|max:100']);$sub=Subcategory::create(['category_id'=>$category->id,'name'=>$request->name]);$audit->log($request,'subcategory.created',$sub,'Subcategory created.',['category_id'=>$category->id]);return back();
    }
    public function destroySubcategory(Request $request, Subcategory $subcategory, AuditService $audit)
    {
        $audit->log($request,'subcategory.deleted',$subcategory,'Subcategory deleted.',['name'=>$subcategory->name]);$subcategory->delete();return back();
    }
}
