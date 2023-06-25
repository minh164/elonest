<?php

namespace Minh164\EloNest\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Minh164\EloNest\ElonestInspector;
use Minh164\EloNest\ModelSetInspection;

/**
 * Command to repair model set.
 */
class RepairingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elonest:set:repair
        {--model= : Namespace of nested model}
        {--og= : Original number of nested set node need be inspected}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Repairing nodes in model set
Example:
php artisan elonest:set:repair --model=App\Models\Category --og=1';

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
            $inspector->repair();
        } catch (\Throwable $e) {
            $this->error(json_encode(['error' => $e->getMessage()]));
            exit();
        }

        $this->info("Original number: $originalNumber set have repaired successfully!");
        exit();
    }
}
