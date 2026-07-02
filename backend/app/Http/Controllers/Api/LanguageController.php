<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LanguageController extends Controller
{
    /**
     * List all languages.
     * React admin expects { data: Language[] }
     */
    public function index(Request $request)
    {
        $query = DB::table('languages');

        // Public storefront only sees active languages
        if (!$request->user()) {
            $query->where('is_active', true);
        }

        $languages = $query->orderBy('is_default', 'desc')->orderBy('name')->get();

        return response()->json(['data' => $languages]);
    }

    /**
     * Get single language by ID.
     */
    public function show($id)
    {
        $language = DB::table('languages')->find($id);

        if (!$language) {
            return response()->json(['message' => 'Language not found.'], 404);
        }

        return response()->json(['language' => $language]);
    }

    /**
     * Create a new language.
     * Safely handles optional columns that may not exist yet.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'        => 'required|string|max:10|unique:languages,code',
            'name'        => 'required|string|max:100',
            'native_name' => 'sometimes|string|max:100',
            'direction'   => 'sometimes|in:ltr,rtl',
            'flag'        => 'sometimes|nullable|string|max:10',
            'is_active'   => 'sometimes|boolean',
            'is_default'  => 'sometimes|boolean',
        ]);

        // If setting as default, clear existing default first
        if (!empty($validated['is_default'])) {
            DB::table('languages')->update(['is_default' => false, 'updated_at' => now()]);
        }

        // Base columns that always exist
        $data = [
            'code'       => strtolower($validated['code']),
            'name'       => $validated['name'],
            'is_active'  => $validated['is_active'] ?? true,
            'is_default' => $validated['is_default'] ?? false,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Optional columns - only insert if they exist in DB
        $optionalColumns = ['native_name', 'direction', 'flag'];
        foreach ($optionalColumns as $col) {
            if (isset($validated[$col]) && Schema::hasColumn('languages', $col)) {
                $data[$col] = $validated[$col];
            }
        }

        // Set defaults for optional columns if they exist
        if (Schema::hasColumn('languages', 'direction') && !isset($data['direction'])) {
            $data['direction'] = 'ltr';
        }

        $id       = DB::table('languages')->insertGetId($data);
        $language = DB::table('languages')->find($id);

        try {
            ActivityLogService::log('language_created', null, [
                'language_id' => $id,
                'code'        => $language->code,
                'name'        => $language->name,
                'is_default'  => $language->is_default,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Language created successfully.',
            'language' => $language,
        ], 201);
    }

    /**
     * Update a language.
     */
    public function update(Request $request, $id)
    {
        $language = DB::table('languages')->find($id);

        if (!$language) {
            return response()->json(['message' => 'Language not found.'], 404);
        }

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'native_name' => 'sometimes|string|max:100',
            'direction'   => 'sometimes|in:ltr,rtl',
            'flag'        => 'sometimes|string|max:10',
            'is_active'   => 'sometimes|boolean',
        ]);

        // Only update columns that exist in DB
        $update = ['updated_at' => now()];
        foreach ($validated as $key => $value) {
            if (in_array($key, ['name', 'is_active']) || Schema::hasColumn('languages', $key)) {
                $update[$key] = $value;
            }
        }

        DB::table('languages')->where('id', $id)->update($update);

        try {
            ActivityLogService::log('language_updated', null, [
                'language_id' => $id,
                'code'        => $language->code,
                'changes'     => array_keys(array_diff_key($update, ['updated_at' => 1])),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Language updated successfully.',
            'language' => DB::table('languages')->find($id),
        ]);
    }

    /**
     * Delete a language (non-default only).
     * Also cleans up associated content translations.
     */
    public function destroy($id)
    {
        $language = DB::table('languages')->find($id);

        if (!$language) {
            return response()->json(['message' => 'Language not found.'], 404);
        }

        if ($language->is_default) {
            return response()->json(['message' => 'Cannot delete the default language.'], 422);
        }

        DB::beginTransaction();
        try {
            // Clean up content translations for this language
            $translationTables = [
                'product_translations'      => 'language',
                'category_translations'     => 'language',
                'content_page_translations' => 'language',
            ];

            foreach ($translationTables as $table => $column) {
                try {
                    DB::table($table)->where($column, $language->code)->delete();
                } catch (\Exception) {
                    // Table may not exist yet - skip silently
                }
            }

            DB::table('languages')->where('id', $id)->delete();
            DB::commit();

            try {
                ActivityLogService::log('language_deleted', null, [
                    'language_id' => $id,
                    'code'        => $language->code,
                    'name'        => $language->name,
                ]);
            } catch (\Exception) {}

            return response()->json(['message' => 'Language deleted successfully.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to delete language.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Toggle active status (cannot disable default).
     */
    public function toggleStatus($id)
    {
        $language = DB::table('languages')->find($id);

        if (!$language) {
            return response()->json(['message' => 'Language not found.'], 404);
        }

        if ($language->is_default && $language->is_active) {
            return response()->json(['message' => 'Cannot disable the default language.'], 422);
        }

        $newStatus = !$language->is_active;

        DB::table('languages')->where('id', $id)->update([
            'is_active'  => $newStatus,
            'updated_at' => now(),
        ]);

        try {
            ActivityLogService::log('language_toggled', null, [
                'language_id' => $id,
                'code'        => $language->code,
                'is_active'   => $newStatus,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Language status updated.',
            'language' => DB::table('languages')->find($id),
        ]);
    }

    /**
     * Set a language as the default.
     * Auto-enables it and clears existing default.
     */
    public function setDefault($id)
    {
        $language = DB::table('languages')->find($id);

        if (!$language) {
            return response()->json(['message' => 'Language not found.'], 404);
        }

        DB::beginTransaction();
        try {
            DB::table('languages')->update(['is_default' => false, 'updated_at' => now()]);

            DB::table('languages')->where('id', $id)->update([
                'is_default' => true,
                'is_active'  => true,
                'updated_at' => now(),
            ]);

            DB::commit();

            try {
                ActivityLogService::log('language_set_default', null, [
                    'language_id' => $id,
                    'code'        => $language->code,
                    'name'        => $language->name,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'  => 'Default language updated.',
                'language' => DB::table('languages')->find($id),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to set default language.', 'error' => $e->getMessage()], 500);
        }
    }
}