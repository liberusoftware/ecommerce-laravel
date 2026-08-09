<?php

namespace App\Console\Commands;

use App\Services\ProductRecommendationEngine;
use Illuminate\Console\Command;

class GenerateProductRecommendations extends Command
{
    protected $signature = 'recommendations:generate';

    protected $description = 'Generate product recommendations using collaborative filtering';

    public function __construct(
        protected ProductRecommendationEngine $engine
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Generating product recommendations...');

        try {
            $this->engine->generateCollaborativeRecommendations();
            $this->info('Product recommendations generated successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to generate recommendations: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
