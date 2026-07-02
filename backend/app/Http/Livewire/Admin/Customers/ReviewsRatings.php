<?php

namespace App\Http\Livewire\Admin\Customers;

use App\Models\ProductReview;
use Livewire\Component;
use Livewire\WithPagination;

class ReviewsRatings extends Component
{
    use WithPagination;

    public string $search          = '';
    public string $approvedFilter  = '';   // '' | '1' | '0'
    public string $ratingFilter    = '';   // '' | '1'..'5'
    public string $verifiedFilter  = '';   // '' | '1'
    public string $sortBy          = 'created_at';
    public string $sortDir         = 'desc';

    // View modal
    public bool           $showDetail = false;
    public ?ProductReview $viewing    = null;

    // Bulk actions
    public array $selected   = [];
    public bool  $selectAll  = false;

    protected $queryString = [
        'search'         => ['except' => ''],
        'approvedFilter' => ['except' => ''],
        'ratingFilter'   => ['except' => ''],
        'verifiedFilter' => ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingApprovedFilter(): void { $this->resetPage(); }
    public function updatingRatingFilter(): void { $this->resetPage(); }

    public function sort(string $col): void
    {
        $this->sortBy  = $col;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    public function updatedSelectAll(bool $val): void
    {
        $this->selected = $val
            ? $this->getBaseQuery()->pluck('id')->map(fn($id) => (string)$id)->toArray()
            : [];
    }

    public function viewReview(int $id): void
    {
        $this->viewing = ProductReview::with([
            'product.translations',
            'product.images',
            'user',
            'order',
        ])->find($id);
        $this->showDetail = true;
    }

    public function approve(int $id): void
    {
        ProductReview::findOrFail($id)->update(['is_approved' => true]);
        if ($this->viewing?->id === $id) {
            $this->viewing->is_approved = true;
        }
        session()->flash('success', 'Review approved.');
    }

    public function reject(int $id): void
    {
        ProductReview::findOrFail($id)->update(['is_approved' => false]);
        if ($this->viewing?->id === $id) {
            $this->viewing->is_approved = false;
        }
        session()->flash('success', 'Review hidden.');
    }

    public function delete(int $id): void
    {
        ProductReview::findOrFail($id)->delete();
        if ($this->viewing?->id === $id) {
            $this->showDetail = false;
        }
        session()->flash('success', 'Review deleted.');
    }

    public function bulkApprove(): void
    {
        if (empty($this->selected)) return;
        ProductReview::whereIn('id', $this->selected)->update(['is_approved' => true]);
        $count = count($this->selected);
        $this->selected  = [];
        $this->selectAll = false;
        session()->flash('success', "{$count} review(s) approved.");
    }

    public function bulkDelete(): void
    {
        if (empty($this->selected)) return;
        $count = count($this->selected);
        ProductReview::whereIn('id', $this->selected)->delete();
        $this->selected  = [];
        $this->selectAll = false;
        session()->flash('success', "{$count} review(s) deleted.");
    }

    protected function getBaseQuery()
    {
        return ProductReview::with(['product.translations', 'user'])
            ->when($this->search, fn($q) =>
                $q->where('title', 'ilike', "%{$this->search}%")
                  ->orWhere('review', 'ilike', "%{$this->search}%")
                  ->orWhereHas('user', fn($uq) =>
                      $uq->whereRaw("first_name || ' ' || last_name ILIKE ?", ["%{$this->search}%"])
                         ->orWhere('email', 'ilike', "%{$this->search}%")
                  )
                  ->orWhereHas('product.translations', fn($tq) =>
                      $tq->where('name', 'ilike', "%{$this->search}%")
                  )
            )
            ->when($this->approvedFilter !== '', fn($q) => $q->where('is_approved', (bool)$this->approvedFilter))
            ->when($this->ratingFilter,  fn($q) => $q->where('rating', $this->ratingFilter))
            ->when($this->verifiedFilter, fn($q) => $q->where('is_verified_purchase', true));
    }

    public function getSummaryProperty(): array
    {
        return ProductReview::selectRaw("
            COUNT(*)                                             AS total,
            COUNT(*) FILTER (WHERE is_approved = true)          AS approved,
            COUNT(*) FILTER (WHERE is_approved = false)         AS pending,
            COUNT(*) FILTER (WHERE is_verified_purchase = true) AS verified,
            COALESCE(ROUND(AVG(rating)::numeric, 2), 0)         AS avg_rating,
            COUNT(*) FILTER (WHERE rating = 5)                  AS five_star,
            COUNT(*) FILTER (WHERE rating = 4)                  AS four_star,
            COUNT(*) FILTER (WHERE rating = 3)                  AS three_star,
            COUNT(*) FILTER (WHERE rating = 2)                  AS two_star,
            COUNT(*) FILTER (WHERE rating = 1)                  AS one_star
        ")->first()->toArray();
    }

    public function render()
    {
        $reviews = $this->getBaseQuery()
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        return view('livewire.admin.customers.reviews-ratings', [
            'reviews' => $reviews,
            'summary' => $this->summary,
        ])->layout('layouts.admin');
    }
}