<?php

namespace App\Http\Livewire\Admin\Marketing;

use App\Models\Banner;
use Livewire\Component;
use Livewire\WithFileUploads;

class Banners extends Component
{
    use WithFileUploads;

    public string $positionFilter = '';
    public string $statusFilter   = '';

    // ── Form modal ─────────────────────────────────────────────────────────────
    public bool  $showModal  = false;
    public bool  $isEditing  = false;
    public ?int  $editingId  = null;

    public string $title           = '';
    public string $subtitle        = '';
    public string $imageUrl        = '';
    public string $mobileImageUrl  = '';
    public string $linkUrl         = '';
    public string $linkText        = '';
    public string $position        = 'hero';
    public string $placement       = 'homepage';
    public bool   $isActive        = true;
    public bool   $openInNewTab    = false;
    public int    $sortOrder       = 0;
    public string $startsAt        = '';
    public string $endsAt          = '';
    public string $textColor       = '#ffffff';
    public string $bgColor         = '#000000';
    public string $overlayOpacity  = '0.3';

    // New image upload
    public $newImage       = null;
    public $newMobileImage = null;

    // ── Delete ─────────────────────────────────────────────────────────────────
    public bool   $showDeleteModal = false;
    public ?int   $deletingId      = null;
    public string $deletingTitle   = '';

    // ── Preview slide-over ─────────────────────────────────────────────────────
    public bool    $showPreview = false;
    public ?Banner $previewing  = null;

    public function updatedNewImage(): void
    {
        $this->validate(['newImage' => 'image|max:4096']);
    }

    public function viewBanner(int $id): void
    {
        $this->previewing  = Banner::find($id);
        $this->showPreview = true;
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->sortOrder = Banner::max('sort_order') + 1;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $b = Banner::findOrFail($id);
        $this->editingId       = $id;
        $this->isEditing       = true;
        $this->title           = $b->title;
        $this->subtitle        = $b->subtitle ?? '';
        $this->imageUrl        = $b->image_url;
        $this->mobileImageUrl  = $b->mobile_image_url ?? '';
        $this->linkUrl         = $b->link_url  ?? '';
        $this->linkText        = $b->link_text ?? '';
        $this->position        = $b->position;
        $this->placement       = $b->placement;
        $this->isActive        = $b->is_active;
        $this->openInNewTab    = $b->open_in_new_tab;
        $this->sortOrder       = $b->sort_order;
        $this->startsAt        = $b->starts_at ? $b->starts_at->format('Y-m-d\TH:i') : '';
        $this->endsAt          = $b->ends_at   ? $b->ends_at->format('Y-m-d\TH:i')   : '';
        $this->textColor       = $b->styles['text_color']       ?? '#ffffff';
        $this->bgColor         = $b->styles['bg_color']         ?? '#000000';
        $this->overlayOpacity  = (string) ($b->styles['overlay_opacity'] ?? '0.3');
        $this->showModal       = true;
        $this->showPreview     = false;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->validate([
            'title'          => 'required|string|max:150',
            'position'       => 'required|in:hero,sidebar,popup,footer,category_top',
            'placement'      => 'required|string|max:50',
            'sortOrder'      => 'integer|min:0',
            'startsAt'       => 'nullable|date',
            'endsAt'         => 'nullable|date|after_or_equal:startsAt',
            'newImage'       => 'nullable|image|max:4096',
            'newMobileImage' => 'nullable|image|max:4096',
            'overlayOpacity' => 'nullable|numeric|min:0|max:1',
        ]);

        // Upload new main image if provided
        $finalImageUrl = $this->imageUrl;
        if ($this->newImage) {
            $path = $this->newImage->store('banners', 'public');
            $finalImageUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        }

        $finalMobileUrl = $this->mobileImageUrl ?: null;
        if ($this->newMobileImage) {
            $path = $this->newMobileImage->store('banners', 'public');
            $finalMobileUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        }

        $data = [
            'title'           => $this->title,
            'subtitle'        => $this->subtitle ?: null,
            'image_url'       => $finalImageUrl,
            'mobile_image_url'=> $finalMobileUrl,
            'link_url'        => $this->linkUrl  ?: null,
            'link_text'       => $this->linkText ?: null,
            'position'        => $this->position,
            'placement'       => $this->placement,
            'is_active'       => $this->isActive,
            'open_in_new_tab' => $this->openInNewTab,
            'sort_order'      => $this->sortOrder,
            'starts_at'       => $this->startsAt ?: null,
            'ends_at'         => $this->endsAt   ?: null,
            'styles'          => [
                'text_color'      => $this->textColor,
                'bg_color'        => $this->bgColor,
                'overlay_opacity' => (float) $this->overlayOpacity,
            ],
            'created_by'      => auth()->id(),
        ];

        if ($this->isEditing) {
            Banner::findOrFail($this->editingId)->update($data);
            $msg = 'Banner updated.';
        } else {
            if (empty($finalImageUrl)) {
                $this->addError('newImage', 'An image is required for new banners.');
                return;
            }
            Banner::create($data);
            $msg = 'Banner created.';
        }

        $this->showModal = false;
        $this->resetForm();
        session()->flash('success', $msg);
    }

    public function toggleActive(int $id): void
    {
        $b = Banner::findOrFail($id);
        $b->update(['is_active' => !$b->is_active]);
    }

    public function moveUp(int $id): void
    {
        $b    = Banner::findOrFail($id);
        $prev = Banner::where('sort_order', '<', $b->sort_order)->orderByDesc('sort_order')->first();
        if ($prev) {
            [$b->sort_order, $prev->sort_order] = [$prev->sort_order, $b->sort_order];
            $b->save(); $prev->save();
        }
    }

    public function moveDown(int $id): void
    {
        $b    = Banner::findOrFail($id);
        $next = Banner::where('sort_order', '>', $b->sort_order)->orderBy('sort_order')->first();
        if ($next) {
            [$b->sort_order, $next->sort_order] = [$next->sort_order, $b->sort_order];
            $b->save(); $next->save();
        }
    }

    public function confirmDelete(int $id): void
    {
        $b = Banner::findOrFail($id);
        $this->deletingId    = $id;
        $this->deletingTitle = $b->title;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        Banner::findOrFail($this->deletingId)->delete();
        $this->showDeleteModal = false;
        session()->flash('success', "Banner deleted.");
    }

    protected function resetForm(): void
    {
        $this->reset([
            'title','subtitle','imageUrl','mobileImageUrl','linkUrl','linkText',
            'startsAt','endsAt','newImage','newMobileImage','editingId',
        ]);
        $this->position       = 'hero';
        $this->placement      = 'homepage';
        $this->isActive       = true;
        $this->openInNewTab   = false;
        $this->sortOrder      = 0;
        $this->textColor      = '#ffffff';
        $this->bgColor        = '#000000';
        $this->overlayOpacity = '0.3';
        $this->isEditing      = false;
        $this->resetErrorBag();
    }

    public function render()
    {
        $positions = ['hero','sidebar','popup','footer','category_top'];
        $banners = Banner::when($this->positionFilter, fn($q) => $q->where('position', $this->positionFilter))
            ->when($this->statusFilter === 'live',      fn($q) => $q->active())
            ->when($this->statusFilter === 'inactive',  fn($q) => $q->where('is_active', false))
            ->when($this->statusFilter === 'scheduled', fn($q) => $q->where('starts_at', '>', now()))
            ->when($this->statusFilter === 'expired',   fn($q) => $q->where('ends_at', '<', now()))
            ->orderBy('position')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('position');

        return view('livewire.admin.marketing.banners', [
            'banners'   => $banners,
            'positions' => $positions,
        ])->layout('layouts.admin');
    }
}