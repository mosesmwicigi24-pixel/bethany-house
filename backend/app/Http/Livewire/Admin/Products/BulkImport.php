<?php

namespace App\Http\Livewire\Admin\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class BulkImport extends Component
{
    use WithFileUploads;

    // ── Upload state ──────────────────────────────────────────────────────────
    public $csvFile         = null;
    public bool $dryRun     = false;

    // ── UI state ──────────────────────────────────────────────────────────────
    public string $step     = 'upload'; // upload | importing | done
    public string $flashMessage = '';
    public string $flashType    = 'error';

    // ── Results ───────────────────────────────────────────────────────────────
    public int   $resultCreated = 0;
    public int   $resultUpdated = 0;
    public array $resultFailed  = [];

    // ── Validation ────────────────────────────────────────────────────────────
    public function updatedCsvFile(): void
    {
        $this->validate([
            'csvFile' => 'required|file|mimes:csv,txt|max:10240',
        ]);
    }

    public function removeCsvFile(): void
    {
        $this->csvFile       = null;
        $this->step          = 'upload';
        $this->resultCreated = 0;
        $this->resultUpdated = 0;
        $this->resultFailed  = [];
        $this->flashMessage  = '';
    }

    // ── Run import ────────────────────────────────────────────────────────────
    public function import(): void
    {
        $this->validate([
            'csvFile' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $this->step = 'importing';

        $path   = $this->csvFile->getRealPath();
        $handle = fopen($path, 'r');

        if (!$handle) {
            $this->flashMessage = 'Could not read the uploaded file.';
            $this->flashType    = 'error';
            $this->step         = 'upload';
            return;
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            $this->flashMessage = 'CSV is empty or malformed.';
            $this->step         = 'upload';
            return;
        }

        $headers      = array_map('trim', $headers);
        $required     = ['sku', 'name_en', 'category_slug', 'price_kes', 'price_usd', 'type'];
        $missing      = array_diff($required, $headers);

        if (!empty($missing)) {
            fclose($handle);
            $this->flashMessage = 'Missing required columns: ' . implode(', ', $missing);
            $this->step         = 'upload';
            return;
        }

        $created = 0;
        $updated = 0;
        $failed  = [];
        $rowNum  = 1;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (count($row) !== count($headers)) {
                    $failed[] = ['row' => $rowNum, 'reason' => 'Column count mismatch'];
                    continue;
                }

                $data = array_combine($headers, array_map('trim', $row));

                $category = Category::where('slug', $data['category_slug'])->first();
                if (!$category) {
                    $failed[] = ['row' => $rowNum, 'reason' => "Category '{$data['category_slug']}' not found"];
                    continue;
                }

                if (!in_array($data['type'] ?? '', ['simple', 'variant', 'made_to_order'])) {
                    $failed[] = ['row' => $rowNum, 'reason' => "Invalid type '{$data['type']}'"];
                    continue;
                }

                $productData = [
                    'name_en'          => $data['name_en'],
                    'name_fr'          => $data['name_fr'] ?? null,
                    'name_pt'          => $data['name_pt'] ?? null,
                    'description_en'   => $data['description_en'] ?? '',
                    'description_fr'   => $data['description_fr'] ?? null,
                    'description_pt'   => $data['description_pt'] ?? null,
                    'category_id'      => $category->id,
                    'price_kes'        => (float) ($data['price_kes'] ?? 0),
                    'price_usd'        => (float) ($data['price_usd'] ?? 0),
                    'type'             => $data['type'],
                    'status'           => in_array($data['status'] ?? '', ['active', 'draft', 'archived']) ? $data['status'] : 'draft',
                    'is_featured'      => filter_var($data['is_featured'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'weight'           => is_numeric($data['weight'] ?? null) ? (float) $data['weight'] : null,
                    'meta_title'       => $data['meta_title'] ?? null,
                    'meta_description' => $data['meta_description'] ?? null,
                ];

                $existing = Product::where('sku', $data['sku'])->first();

                if (!$this->dryRun) {
                    if ($existing) {
                        $existing->update($productData);
                        $updated++;
                    } else {
                        $slug = Str::slug($data['name_en']);
                        $base = $slug; $c = 1;
                        while (Product::where('slug', $slug)->exists()) { $slug = "{$base}-{$c}"; $c++; }

                        $product = Product::create(array_merge($productData, ['sku' => $data['sku'], 'slug' => $slug]));

                        if ($data['type'] === 'simple') {
                            ProductVariant::create([
                                'product_id' => $product->id,
                                'sku'        => $data['sku'],
                                'name'       => 'Default',
                                'price_kes'  => $productData['price_kes'],
                                'price_usd'  => $productData['price_usd'],
                            ]);
                        }
                        $created++;
                    }
                } else {
                    // dry run: just count
                    $existing ? $updated++ : $created++;
                }
            }

            fclose($handle);

            if (!$this->dryRun) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            $this->resultCreated = $created;
            $this->resultUpdated = $updated;
            $this->resultFailed  = $failed;
            $this->step          = 'done';

        } catch (\Exception $e) {
            fclose($handle);
            DB::rollBack();
            $this->flashMessage = 'Import failed: ' . $e->getMessage();
            $this->flashType    = 'error';
            $this->step         = 'upload';
        }
    }

    // ── Column reference for the view ─────────────────────────────────────────
    #[Computed]
    public function columns(): array
    {
        return [
            ['sku',              true,  'BAG-001'],
            ['name_en',          true,  'Leather Tote Bag'],
            ['name_fr',          false, 'Sac Fourre-tout'],
            ['name_pt',          false, 'Bolsa de Couro'],
            ['description_en',   true,  'A handcrafted leather bag…'],
            ['description_fr',   false, '(optional)'],
            ['description_pt',   false, '(optional)'],
            ['category_slug',    true,  'bags'],
            ['price_kes',        true,  '4500'],
            ['price_usd',        true,  '35.00'],
            ['type',             true,  'simple | variant | made_to_order'],
            ['status',           false, 'draft | active | archived'],
            ['is_featured',      false, 'true | false'],
            ['weight',           false, '0.8'],
            ['meta_title',       false, 'Buy Leather Tote Online'],
            ['meta_description', false, 'Handcrafted leather tote…'],
        ];
    }

    public function render()
    {
        return view('livewire.admin.products.import', [])->layout('layouts.admin');
    }
}