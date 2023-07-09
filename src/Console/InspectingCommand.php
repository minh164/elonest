<?php

namespace Minh164\EloNest\Console;

use Illuminate\Console\Command;
use Minh164\EloNest\ElonestInspector;
use Minh164\EloNest\ModelSetInspection;

/**
 * Command to inspect model set.
 */
class InspectingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elonest:set:inspect
        {--model= : Namespace of nested model}
        {--og= : Original number of nested set node need be inspected}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspecting nodes in model set
Example:
php artisan elonest:set:inspect --model=App\\\\Models\\\\Model --og=1';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $class = $this->option('model');
        $originalNumber = (int) $this->option('og');

        if (!$class) {
            $this->error('Please provide --model'); exit();
        }
        if (!$originalNumber) {
            $this->error('Please provide --og'); exit();

        }
        if (!is_int($originalNumber)) {
            $this->error('--og must be integer'); exit();
        }

        try {
            $inspector = new ElonestInspector($class, $originalNumber);
            $inspector->inspect();

            if ($inspector->isBrokenSet()) {
                $reportTable = (new ModelSetInspection())->getTable();
                $this->error("Model set is broken! You can check errors in $reportTable table with ID: {$inspector->getNewestInspection()->id}");
            } else {
                $this->info("Model set is fine!");
            }
        } catch (\Throwable $e) {
            $this->error(json_encode(['error' => $e->getMessage()]));
            exit();
        }

        $this->info('Model set have inspected successfully!');
        exit();
    }
}
