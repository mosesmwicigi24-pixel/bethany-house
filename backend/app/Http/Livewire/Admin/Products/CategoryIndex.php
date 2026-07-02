<?php

namespace App\Http\Livewire\Admin\Products;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class CategoryIndex extends Component
{
    use WithFileUploads;

    // ── State ─────────────────────────────────────────────────────────────────
    public string $search       = '';
    public string $flashMessage = '';
    public string $flashType    = 'success';

    // ── Modal ─────────────────────────────────────────────────────────────────
    public bool   $showModal       = false;
    public bool   $showDeleteModal = false;
    public ?int   $editId          = null;
    public ?int   $deleteId        = null;
    public string $deleteName      = '';
    public string $activeTab       = 'en';

    // ── Category form fields ──────────────────────────────────────────────────
    public string  $name_en          = '';
    public string  $name_fr          = '';
    public string  $name_pt          = '';
    public string  $description_en   = '';
    public string  $description_fr   = '';
    public string  $description_pt   = '';
    public string  $parent_id        = '';
    public string  $icon             = '';
    public int     $sort_order       = 0;
    public bool    $is_active        = true;
    public string  $meta_title       = '';
    public string  $meta_description = '';
    public         $image            = null;

    // ── Computed ─────────────────────────────────────────────────────────────
    #[Computed]
    public function categories()
    {
        // Names live in category_translations. Join for search; eager-load for display.
        $query = Category::withoutGlobalScopes()
            ->withCount('products')
            ->with(['parent', 'translations'])
            ->whereNull('categories.deleted_at')
            ->orderBy('sort_order');

        if ($this->search) {
            $query->whereHas('translations', function ($q) {
                $q->where('language_code', 'en')
                  ->where('name', 'like', '%' . $this->search . '%');
            });
        }

        return $query->get();
    }

    #[Computed]
    public function tree()
    {
        return Category::withoutGlobalScopes()
            ->with(['children.children', 'children.translations', 'translations'])
            ->withCount('products')
            ->whereNull('categories.deleted_at')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function parentOptions()
    {
        return Category::withoutGlobalScopes()
            ->with('translations')
            ->whereNull('categories.deleted_at')
            ->whereNull('parent_id')
            ->when($this->editId, fn ($q) => $q->where('id', '!=', $this->editId))
            ->orderBy('sort_order')
            ->get();
    }

    // ── Modal helpers ─────────────────────────────────────────────────────────
    public function openCreate(): void
    {
        $this->resetForm();
        $this->editId    = null;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $cat = Category::with('translations')->findOrFail($id);

        $en = $cat->translations->firstWhere('language_code', 'en');
        $fr = $cat->translations->firstWhere('language_code', 'fr');
        $pt = $cat->translations->firstWhere('language_code', 'pt');

        $this->editId           = $id;
        $this->name_en          = $en?->name        ?? '';
        $this->name_fr          = $fr?->name        ?? '';
        $this->name_pt          = $pt?->name        ?? '';
        $this->description_en   = $en?->description ?? '';
        $this->description_fr   = $fr?->description ?? '';
        $this->description_pt   = $pt?->description ?? '';
        $this->parent_id        = (string) ($cat->parent_id ?? '');
        $this->icon             = $cat->icon         ?? '';
        $this->sort_order       = $cat->sort_order   ?? 0;
        $this->is_active        = $cat->is_active    !== false;
        $this->meta_title       = $en?->meta_title        ?? '';
        $this->meta_description = $en?->meta_description  ?? '';
        $this->image            = null;
        $this->showModal        = true;
    }

    public function confirmDelete(int $id, string $name): void
    {
        $this->deleteId        = $id;
        $this->deleteName      = $name;
        $this->showDeleteModal = true;
    }

    private function resetForm(): void
    {
        $this->name_en = $this->name_fr = $this->name_pt = '';
        $this->description_en = $this->description_fr = $this->description_pt = '';
        $this->parent_id = $this->icon = $this->meta_title = $this->meta_description = '';
        $this->sort_order = 0;
        $this->is_active  = true;
        $this->image      = null;
        $this->activeTab  = 'en';
        $this->resetErrorBag();
    }

    // ── Save ──────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate([
            'name_en'          => 'required|string|max:255',
            'name_fr'          => 'nullable|string|max:255',
            'name_pt'          => 'nullable|string|max:255',
            'description_en'   => 'nullable|string',
            'description_fr'   => 'nullable|string',
            'description_pt'   => 'nullable|string',
            'parent_id'        => 'nullable|exists:categories,id',
            'icon'             => 'nullable|string|max:100',
            'sort_order'       => 'nullable|integer|min:0',
            'meta_title'       => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'image'            => 'nullable|image|max:2048',
        ]);

        DB::beginTransaction();
        try {
            // ── Core category data (no name/description - those go to translations) ──
            $categoryData = [
                'parent_id'  => $this->parent_id ?: null,
                'icon'       => $this->icon       ?: null,
                'sort_order' => $this->sort_order,
                'is_active'  => $this->is_active,
            ];

            if ($this->image) {
                $categoryData['image_url'] = $this->image->store('categories', 'public');
            }

            if ($this->editId) {
                $cat = Category::findOrFail($this->editId);

                // Update slug if English name changed
                $enTrans = $cat->translations->firstWhere('language_code', 'en');
                if (($enTrans?->name ?? '') !== $this->name_en) {
                    $categoryData['slug'] = $this->generateSlug($this->name_en, $this->editId);
                }

                $cat->update($categoryData);
            } else {
                // Auto sort_order
                if (!$this->sort_order) {
                    $max = Category::where('parent_id', $this->parent_id ?: null)->max('sort_order');
                    $categoryData['sort_order'] = ($max ?? 0) + 1;
                }

                $categoryData['slug'] = $this->generateSlug($this->name_en);
                $cat = Category::create($categoryData);
            }

            // ── Upsert translations ───────────────────────────────────────────
            $translations = [
                'en' => [
                    'name'             => $this->name_en,
                    'description'      => $this->description_en ?: null,
                    'meta_title'       => $this->meta_title       ?: null,
                    'meta_description' => $this->meta_description ?: null,
                ],
                'fr' => [
                    'name'             => $this->name_fr       ?: null,
                    'description'      => $this->description_fr ?: null,
                    'meta_title'       => null,
                    'meta_description' => null,
                ],
                'pt' => [
                    'name'             => $this->name_pt       ?: null,
                    'description'      => $this->description_pt ?: null,
                    'meta_title'       => null,
                    'meta_description' => null,
                ],
            ];

            foreach ($translations as $lang => $trans) {
                // Skip if no name provided for non-EN languages
                if ($lang !== 'en' && empty($trans['name'])) {
                    continue;
                }

                DB::table('category_translations')->updateOrInsert(
                    ['category_id' => $cat->id, 'language_code' => $lang],
                    array_merge($trans, ['updated_at' => now(), 'created_at' => now()])
                );
            }

            DB::commit();

            $this->flash($this->editId ? "'{$this->name_en}' updated." : 'Category created.');
            $this->showModal = false;
            $this->resetForm();
            unset($this->categories, $this->tree);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->flash('Save failed: ' . $e->getMessage(), 'error');
        }
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    public function delete(): void
    {
        $cat = Category::withCount(['products', 'children'])->findOrFail($this->deleteId);

        if ($cat->products_count > 0) {
            $this->flash('Cannot delete: category has products. Reassign them first.', 'error');
            $this->showDeleteModal = false;
            return;
        }
        if ($cat->children_count > 0) {
            $this->flash('Cannot delete: category has subcategories.', 'error');
            $this->showDeleteModal = false;
            return;
        }

        if ($cat->image_url) {
            Storage::disk('public')->delete($cat->image_url);
        }

        // Translations are deleted via DB cascade or manually
        DB::table('category_translations')->where('category_id', $this->deleteId)->delete();
        $cat->delete();

        $this->flash("'{$this->deleteName}' deleted.");
        $this->showDeleteModal = false;
        unset($this->categories, $this->tree);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function generateSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);
        $base = $slug;
        $i    = 1;
        while (Category::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }
        return $slug;
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $this->flashMessage = $message;
        $this->flashType    = $type;
    }

    public function render()
    {
        return view('livewire.admin.products.categories', [])->layout('layouts.admin');
    }
}