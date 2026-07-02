<?php

namespace App\Http\Livewire\Admin\Marketing;

use App\Models\EmailCampaign;
use App\Models\Customer;
use Livewire\Component;
use Livewire\WithPagination;

class EmailCampaigns extends Component
{
    use WithPagination;

    public string $search       = '';
    public string $statusFilter = '';
    public string $sortBy       = 'created_at';
    public string $sortDir      = 'desc';

    // ── Compose modal ──────────────────────────────────────────────────────────
    public bool  $showModal  = false;
    public bool  $isEditing  = false;
    public ?int  $editingId  = null;
    public int   $activeStep = 1; // 1=Details, 2=Content, 3=Audience, 4=Review

    // Step 1 – Details
    public string $campaignName  = '';
    public string $subject       = '';
    public string $previewText   = '';
    public string $fromName      = '';
    public string $fromEmail     = '';
    public string $replyTo       = '';

    // Step 2 – Content
    public string $htmlBody  = '';
    public string $plainBody = '';

    // Step 3 – Audience
    public string $audience        = 'all_customers';
    public string $scheduledAt     = '';

    // ── Detail slide-over ──────────────────────────────────────────────────────
    public bool          $showDetail = false;
    public ?EmailCampaign $viewing   = null;

    // ── Delete ─────────────────────────────────────────────────────────────────
    public bool   $showDeleteModal = false;
    public ?int   $deletingId      = null;
    public string $deletingName    = '';

    protected $queryString = [
        'search'       => ['except' => ''],
        'statusFilter' => ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }

    public function viewCampaign(int $id): void
    {
        $this->viewing    = EmailCampaign::find($id);
        $this->showDetail = true;
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->fromName  = config('mail.from.name', 'Bethany House');
        $this->fromEmail = config('mail.from.address', '');
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $c = EmailCampaign::findOrFail($id);
        if (!in_array($c->status, ['draft', 'scheduled'])) {
            session()->flash('error', 'Only draft or scheduled campaigns can be edited.');
            return;
        }
        $this->editingId   = $id;
        $this->isEditing   = true;
        $this->campaignName= $c->name;
        $this->subject     = $c->subject;
        $this->previewText = $c->preview_text ?? '';
        $this->fromName    = $c->from_name ?? '';
        $this->fromEmail   = $c->from_email ?? '';
        $this->replyTo     = $c->reply_to ?? '';
        $this->htmlBody    = $c->html_body;
        $this->plainBody   = $c->plain_body ?? '';
        $this->audience    = $c->audience;
        $this->scheduledAt = $c->scheduled_at ? $c->scheduled_at->format('Y-m-d\TH:i') : '';
        $this->activeStep  = 1;
        $this->showModal   = true;
        $this->showDetail  = false;
        $this->resetErrorBag();
    }

    public function nextStep(): void { $this->activeStep = min(4, $this->activeStep + 1); }
    public function prevStep(): void { $this->activeStep = max(1, $this->activeStep - 1); }

    public function save(string $status = 'draft'): void
    {
        $this->validate([
            'campaignName' => 'required|string|max:150',
            'subject'      => 'required|string|max:255',
            'fromEmail'    => 'nullable|email',
            'replyTo'      => 'nullable|email',
            'htmlBody'     => 'required|string',
            'audience'     => 'required|in:all_customers,active,business,individual',
            'scheduledAt'  => 'nullable|date|after:now',
        ]);

        $recipientCount = $this->estimateRecipientCount();

        $finalStatus = $status;
        if ($status === 'scheduled' && !$this->scheduledAt) {
            $finalStatus = 'draft';
        }

        $data = [
            'name'            => $this->campaignName,
            'subject'         => $this->subject,
            'preview_text'    => $this->previewText ?: null,
            'html_body'       => $this->htmlBody,
            'plain_body'      => $this->plainBody ?: null,
            'from_name'       => $this->fromName ?: null,
            'from_email'      => $this->fromEmail ?: null,
            'reply_to'        => $this->replyTo ?: null,
            'status'          => $finalStatus,
            'audience'        => $this->audience,
            'scheduled_at'    => $this->scheduledAt ?: null,
            'recipient_count' => $recipientCount,
            'created_by'      => auth()->id(),
        ];

        if ($this->isEditing) {
            EmailCampaign::findOrFail($this->editingId)->update($data);
            $msg = 'Campaign updated.';
        } else {
            EmailCampaign::create($data);
            $msg = 'Campaign created.';
        }

        $this->showModal = false;
        $this->resetForm();
        session()->flash('success', $msg);
    }

    protected function estimateRecipientCount(): int
    {
        $q = Customer::where('status', 'active');
        if ($this->audience === 'business')    $q->where('customer_type', 'business');
        if ($this->audience === 'individual')  $q->where('customer_type', 'individual');
        return $q->whereNotNull('email')->count();
    }

    public function cancelCampaign(int $id): void
    {
        EmailCampaign::findOrFail($id)->update(['status' => 'cancelled']);
        session()->flash('success', 'Campaign cancelled.');
    }

    public function confirmDelete(int $id): void
    {
        $c = EmailCampaign::findOrFail($id);
        $this->deletingId   = $id;
        $this->deletingName = $c->name;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        EmailCampaign::findOrFail($this->deletingId)->delete();
        $this->showDeleteModal = false;
        session()->flash('success', "{$this->deletingName} deleted.");
    }

    protected function resetForm(): void
    {
        $this->reset([
            'campaignName','subject','previewText','fromName','fromEmail',
            'replyTo','htmlBody','plainBody','scheduledAt','editingId',
        ]);
        $this->audience    = 'all_customers';
        $this->activeStep  = 1;
        $this->isEditing   = false;
        $this->resetErrorBag();
    }

    public function getSummaryProperty(): array
    {
        return EmailCampaign::selectRaw("
            COUNT(*)                                           AS total,
            COUNT(*) FILTER (WHERE status = 'draft')          AS draft,
            COUNT(*) FILTER (WHERE status = 'sent')           AS sent,
            COUNT(*) FILTER (WHERE status = 'scheduled')      AS scheduled,
            COALESCE(SUM(sent_count), 0)                      AS total_sent,
            COALESCE(SUM(opened_count), 0)                    AS total_opened
        ")->first()->toArray();
    }

    public function render()
    {
        $campaigns = EmailCampaign::when($this->search, fn($q) =>
                $q->where('name', 'ilike', "%{$this->search}%")
                  ->orWhere('subject', 'ilike', "%{$this->search}%")
            )
            ->when($this->statusFilter, fn($q) => $q->where('status', $this->statusFilter))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);

        return view('livewire.admin.marketing.email-campaigns', [
            'campaigns' => $campaigns,
            'summary'   => $this->summary,
        ])->layout('layouts.admin');
    }
}