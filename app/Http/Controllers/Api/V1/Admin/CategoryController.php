<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function __construct(protected ImageService $imageService) {}

    public function index(Request $request)
    {
        $public = !$request->is('api/v1/admin/*');
        $query = Category::query()->with('parent:id,name,slug')->with(['children' => function ($query) use ($public) {
            if ($public) $query->where('status', true);
            $query->orderBy('sorting_order')->orderBy('id');
        }])->orderBy('sorting_order')->orderBy('id');
        if ($public) $query->where('status', true)->where(function ($query) {
            $query->whereNull('parent_id')->orWhereHas('parent', fn ($parent) => $parent->where('status', true));
        });
        if ($request->boolean('roots')) $query->whereNull('parent_id');
        if ($request->has('all')) return response()->json(['data' => $query->get()]);
        return response()->json($query->paginate(min(max((int) $request->get('per_page', 10), 1), 100)));
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($category?->id)],
            'code' => 'nullable|string|max:10',
            'short_description' => 'nullable|string|max:255',
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,avif|max:5120',
            'remove_image' => 'nullable|boolean',
            'sorting_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ]);
        $parentId = $data['parent_id'] ?? null;
        if ($parentId) {
            $parent = Category::findOrFail($parentId);
            if ($parent->parent_id || ($category && ($parent->id === $category->id || $category->children()->exists()))) {
                throw ValidationException::withMessages(['parent_id' => 'Choose a main category. A category with subcategories cannot become a subcategory.']);
            }
        }
        $slug = Str::slug($data['name']);
        if (!$slug || Category::where('slug', $slug)->when($category, fn ($q) => $q->where('id', '!=', $category->id))->exists()) {
            throw ValidationException::withMessages(['name' => 'This name produces an empty or already-used URL. Choose a different name.']);
        }
        $data['slug'] = $slug;
        $data['code'] = strtoupper($data['code'] ?? '');
        $data['parent_id'] = $parentId;
        unset($data['image'], $data['remove_image']);
        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($request->hasFile('image')) $data['image'] = $this->imageService->upload($request->file('image'), 'categories')['path'];
        $category = Category::create($data);
        return response()->json(['message' => 'Category created successfully', 'data' => $category->load('parent', 'children')], 201);
    }

    public function show(Category $category)
    {
        return response()->json(['data' => $category->load('parent', 'children')]);
    }

    public function showBySlug($slug)
    {
        $category = Category::where('slug', $slug)->where('status', true)
            ->where(fn ($query) => $query->whereNull('parent_id')->orWhereHas('parent', fn ($parent) => $parent->where('status', true)))
            ->with(['children' => fn ($query) => $query->where('status', true)->orderBy('sorting_order')])->firstOrFail();
        return response()->json(['data' => $category]);
    }

    public function update(Request $request, Category $category)
    {
        $data = $this->validated($request, $category);
        $oldImage = $category->image;
        if ($request->hasFile('image')) $data['image'] = $this->imageService->upload($request->file('image'), 'categories')['path'];
        elseif ($request->boolean('remove_image')) $data['image'] = null;
        $category->update($data);
        if (array_key_exists('image', $data) && $oldImage !== $category->image) $this->imageService->delete($oldImage);
        return response()->json(['message' => 'Category updated successfully', 'data' => $category->load('parent', 'children')]);
    }

    public function destroy(Category $category)
    {
        if ($category->children()->exists() || $category->products()->exists()) {
            throw ValidationException::withMessages(['category' => 'Move or delete the assigned products and subcategories before deleting this category.']);
        }
        $image = $category->image;
        $category->delete();
        $this->imageService->delete($image);
        return response()->json(['message' => 'Category deleted successfully']);
    }
}
