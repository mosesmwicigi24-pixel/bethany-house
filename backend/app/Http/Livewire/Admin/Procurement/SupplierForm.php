<?php

namespace App\Http\Livewire\Admin\Procurement;

use App\Models\Supplier;
use Livewire\Component;

class SupplierForm extends Component
{
    public ?Supplier $supplier = null;
    public bool      $isEditing = false;

    public string $code           = '';
    public string $name           = '';
    public string $contactPerson  = '';
    public string $email          = '';
    public string $phone          = '';
    public string $addressLine1   = '';
    public string $addressLine2   = '';
    public string $city           = '';
    public string $stateProvince  = '';
    public string $postalCode     = '';
    public string $countryCode    = '';
    public string $taxId          = '';
    public string $paymentTerms   = '';
    public string $rating         = '';
    public bool   $isActive       = true;
    public string $notes          = '';

    public string $flashMessage = '';
    public string $flashType    = 'success';

    public function mount(?Supplier $supplier = null): void
    {
        if ($supplier && $supplier->exists) {
            $this->supplier     = $supplier;
            $this->isEditing    = true;
            $this->code         = $supplier->code;
            $this->name         = $supplier->name;
            $this->contactPerson= $supplier->contact_person ?? '';
            $this->email        = $supplier->email ?? '';
            $this->phone        = $supplier->phone ?? '';
            $this->addressLine1 = $supplier->address_line1 ?? '';
            $this->addressLine2 = $supplier->address_line2 ?? '';
            $this->city         = $supplier->city ?? '';
            $this->stateProvince= $supplier->state_province ?? '';
            $this->postalCode   = $supplier->postal_code ?? '';
            $this->countryCode  = $supplier->country_code ?? '';
            $this->taxId        = $supplier->tax_id ?? '';
            $this->paymentTerms = $supplier->payment_terms ?? '';
            $this->rating       = $supplier->rating ? (string) $supplier->rating : '';
            $this->isActive     = $supplier->is_active;
            $this->notes        = $supplier->notes ?? '';
        }
    }

    protected function rules(): array
    {
        $unique = $this->isEditing
            ? ','. $this->supplier->id
            : '';
        return [
            'code'         => "required|string|max:50|unique:suppliers,code{$unique}",
            'name'         => 'required|string|max:255',
            'contactPerson'=> 'nullable|string|max:255',
            'email'        => 'nullable|email|max:255',
            'phone'        => 'nullable|string|max:30',
            'addressLine1' => 'nullable|string|max:255',
            'addressLine2' => 'nullable|string|max:255',
            'city'         => 'nullable|string|max:100',
            'stateProvince'=> 'nullable|string|max:100',
            'postalCode'   => 'nullable|string|max:20',
            'countryCode'  => 'nullable|string|max:2',
            'taxId'        => 'nullable|string|max:100',
            'paymentTerms' => 'nullable|string|max:255',
            'rating'       => 'nullable|numeric|min:0|max:5',
            'isActive'     => 'boolean',
            'notes'        => 'nullable|string',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'code'           => strtoupper($this->code),
            'name'           => $this->name,
            'contact_person' => $this->contactPerson ?: null,
            'email'          => $this->email ?: null,
            'phone'          => $this->phone ?: null,
            'address_line1'  => $this->addressLine1 ?: null,
            'address_line2'  => $this->addressLine2 ?: null,
            'city'           => $this->city ?: null,
            'state_province' => $this->stateProvince ?: null,
            'postal_code'    => $this->postalCode ?: null,
            'country_code'   => $this->countryCode ? strtoupper($this->countryCode) : null,
            'tax_id'         => $this->taxId ?: null,
            'payment_terms'  => $this->paymentTerms ?: null,
            'rating'         => $this->rating ?: null,
            'is_active'      => $this->isActive,
            'notes'          => $this->notes ?: null,
        ];

        if ($this->isEditing) {
            $this->supplier->update($data);
            session()->flash('success', 'Supplier updated successfully.');
            $this->redirect(route('procurement.suppliers'), navigate: true);
        } else {
            $supplier = Supplier::create($data);
            session()->flash('success', 'Supplier created successfully.');
            $this->redirect(route('procurement.suppliers'), navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.admin.procurement.supplier-form')->layout('layouts.admin');
    }
}