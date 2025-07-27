<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Optimization;

use Psr\Log\LoggerInterface;

/**
 * Geographic Optimizer
 *
 * Optimizes routing based on geographic location for minimal latency
 */
class GeographicOptimizer
{
    private LoggerInterface $logger;
    private array $config;
    private bool $isRunning = false;
    private array $edgeLocations = [];

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->initializeEdgeLocations();
    }

    public function start(): \Generator
    {
        $this->isRunning = true;
        $this->logger->debug('Geographic optimizer started');
        return yield;
    }

    public function stop(): \Generator
    {
        $this->isRunning = false;
        $this->logger->debug('Geographic optimizer stopped');
        return yield;
    }

    public function findOptimalRoute(array $clientLocation, array $availableEndpoints): \Generator
    {
        $bestRoute = null;
        $lowestLatency = PHP_FLOAT_MAX;

        foreach ($availableEndpoints as $endpoint) {
            $distance = $this->calculateDistance($clientLocation, $endpoint['location']);
            $estimatedLatency = $this->estimateLatencyFromDistance($distance);

            if ($estimatedLatency < $lowestLatency) {
                $lowestLatency = $estimatedLatency;
                $bestRoute = [
                    'endpoint' => $endpoint,
                    'distance_km' => $distance,
                    'estimated_latency' => $estimatedLatency,
                    'edge_location' => $this->findNearestEdge($clientLocation)
                ];
            }
        }

        return yield $bestRoute;
    }

    private function initializeEdgeLocations(): void
    {
        $this->edgeLocations = [
            'us-east-1' => ['lat' => 39.0458, 'lon' => -76.6413],
            'us-west-1' => ['lat' => 37.7749, 'lon' => -122.4194],
            'eu-west-1' => ['lat' => 53.4084, 'lon' => -2.9916],
            'ap-southeast-1' => ['lat' => 1.3521, 'lon' => 103.8198],
            'ap-northeast-1' => ['lat' => 35.6762, 'lon' => 139.6503]
        ];
    }

    private function calculateDistance(array $location1, array $location2): float
    {
        $lat1 = deg2rad($location1['lat']);
        $lon1 = deg2rad($location1['lon']);
        $lat2 = deg2rad($location2['lat']);
        $lon2 = deg2rad($location2['lon']);

        $deltaLat = $lat2 - $lat1;
        $deltaLon = $lon2 - $lon1;

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1) * cos($lat2) *
             sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $earthRadius = 6371; // km

        return $earthRadius * $c;
    }

    private function estimateLatencyFromDistance(float $distanceKm): float
    {
        // Rough estimate: ~0.1ms per 100km + base latency
        $baseLatency = 0.005; // 5ms base
        $propagationDelay = ($distanceKm / 100) * 0.001; // 0.1ms per 100km
        
        return $baseLatency + $propagationDelay;
    }

    private function findNearestEdge(array $clientLocation): string
    {
        $nearestEdge = null;
        $shortestDistance = PHP_FLOAT_MAX;

        foreach ($this->edgeLocations as $edgeName => $edgeLocation) {
            $distance = $this->calculateDistance($clientLocation, $edgeLocation);
            if ($distance < $shortestDistance) {
                $shortestDistance = $distance;
                $nearestEdge = $edgeName;
            }
        }

        return $nearestEdge ?? 'us-east-1';
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }
}