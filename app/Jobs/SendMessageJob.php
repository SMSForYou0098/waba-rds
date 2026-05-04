<?php
// app/Jobs/SendMessageJob.php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http; // Or use Guzzle if preferred

class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $number;
    protected $payload;
    protected $campaignId;

    public function __construct($number, $payload, $campaignId)
    {
        $this->number = $number;
        $this->payload = $payload;
        $this->campaignId = $campaignId;
    }

    public function handle()
    {
        // Send message logic
        $response = Http::withToken($this->payload['authToken'])->post($this->payload['api'], $this->generatePayload());

        if ($response->successful()) {
            // Record the message status
            Report::create([
                'campaign_id' => $this->campaignId,
                'message_id' => $response->json('messages.0.id'),
                'mobile_number' => $this->number,
            ]);
        } else {
            // Handle error
            // Optionally retry or log error
        }
    }

    protected function generatePayload()
    {
        // Implement the payload generation logic based on the provided payload data
    }
}


