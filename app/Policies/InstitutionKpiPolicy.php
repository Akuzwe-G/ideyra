<?php

namespace App\Policies;

use App\Models\InstitutionKpi;
use App\Models\User;
use App\Support\IntelligenceAccess;

class InstitutionKpiPolicy
{
    public function view(User $user, InstitutionKpi $kpi): bool
    {
        return IntelligenceAccess::kpi($user, $kpi->institution_id, 'kpis.view');
    }

    public function update(User $user, InstitutionKpi $kpi): bool
    {
        return IntelligenceAccess::kpi($user, $kpi->institution_id, 'kpis.edit');
    }

    public function approve(User $user, InstitutionKpi $kpi): bool
    {
        return IntelligenceAccess::kpi($user, $kpi->institution_id, 'kpis.approve');
    }
}
