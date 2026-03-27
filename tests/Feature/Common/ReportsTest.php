<?php

namespace Tests\Feature\Common;

use App\Models\Common\Report;
use Tests\Feature\FeatureTestCase;

class ReportsTest extends FeatureTestCase
{
    public function testItShouldShowProfitLossReportWhenSettingsAreMissing()
    {
        $report = Report::create([
            'company_id' => company_id(),
            'class' => 'App\Reports\ProfitLoss',
            'name' => 'Legacy Profit Loss',
            'description' => 'Report without saved settings',
            'settings' => null,
            'created_from' => 'core::test',
            'created_by' => $this->user->id,
        ]);

        $this->loginAs()
            ->get(route('reports.show', $report->id))
            ->assertOk()
            ->assertSeeText('Legacy Profit Loss')
            ->assertSeeText(trans('reports.net_profit'));
    }
}
