<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\FormsController;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Outlet;
use App\Support\Automations;
use Illuminate\Console\Command;

/**
 * forms:remind — every fifteen minutes: a checklist due today with nothing
 * submitted by its due time plus the automation's threshold is written as
 * a `missed` row (once) and the outlet's managers are told. A submission
 * later that day turns the row late. Checked per outlet the template
 * applies to; only outlets that are active.
 */
class FormsRemind extends Command
{
    protected $signature = 'forms:remind {--dry-run : List, write nothing}';
    protected $description = 'Record missed checklists and tell the outlet managers';

    public function handle(): int
    {
        $auto = Automations::active('checklist_missed');
        $grace = (int) ($auto['threshold'] ?? 30);
        $today = today(); $now = now(); $n = 0;
        $outlets = Outlet::where('is_active', true)->get(['id', 'name']);
        foreach (FormTemplate::where('active', true)->where('kind', 'checklist')->get() as $t) {
            if (!$t->dueOn($today) || !($due = $t->dueAt($today))) continue;
            if ($now->lt($due->copy()->addMinutes($grace))) continue;
            foreach ($outlets as $o) {
                if ($t->outlet_id && $t->outlet_id !== $o->id) continue;
                if (FormSubmission::where('form_template_id', $t->id)->where('outlet_id', $o->id)->where('for_date', $today->toDateString())->exists()) continue;
                $this->line("missed: {$t->name} at {$o->name} (due {$due->format('H:i')})");
                $n++;
                if ($this->option('dry-run')) continue;
                $s = FormSubmission::create(['form_template_id' => $t->id, 'outlet_id' => $o->id, 'user_id' => null, 'for_date' => $today->toDateString(), 'status' => 'missed', 'due_at' => $due, 'flagged' => true]);
                if ($auto) FormsController::tellManagers($s, $t, null);
            }
        }
        $this->info(($this->option('dry-run') ? 'Would record ' : 'Recorded ') . "{$n} missed checklist(s).");
        return self::SUCCESS;
    }
}
