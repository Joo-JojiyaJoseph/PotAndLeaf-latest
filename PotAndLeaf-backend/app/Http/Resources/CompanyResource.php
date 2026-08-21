<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesMediaUrls;
use App\Models\Company;
use App\Support\ProtectedRecords;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    use ResolvesMediaUrls;

    /** @var array<string, int>|null */
    protected ?array $statistics = null;

    /** Attach statistics for the company detail response (do not use a custom constructor — breaks ::collection()). */
    public static function withStatistics(Company $company, array $statistics): self
    {
        $resource = new self($company);
        $resource->statistics = $statistics;

        return $resource;
    }

    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'code'         => $this->code,
            'legal_name'   => $this->legal_name,
            'gst_number'   => $this->gst_number,
            'state'        => $this->state,
            'state_code'   => $this->state_code,
            'address'      => $this->address,
            'locations'    => $this->locations,
            'phone'        => $this->phone,
            'email'        => $this->email,
            'logo'         => $this->mediaUrl($this->logo),
            'photo'        => $this->mediaUrl($this->logo),
            'description'  => $this->description,
            'is_active'    => (bool) $this->is_active,
            'is_protected' => ProtectedRecords::isProtectedCompany($this->resource),
            'users_count'  => $this->when($this->users_count !== null, $this->users_count),
            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),
            'statistics'   => $this->when($this->statistics !== null, $this->statistics),
        ];
    }
}
