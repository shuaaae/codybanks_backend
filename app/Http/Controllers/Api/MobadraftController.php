<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MobadraftController extends Controller
{
    private $baseUrl = 'https://mobadraft.com/api';
    
    /**
     * Get last updated timestamp from mobadraft
     */
    public function getLastUpdated()
    {
        try {
            $response = Http::timeout(10)->get($this->baseUrl . '/last_updated');
            
            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'data' => $response->json(),
                    'timestamp' => now()->toISOString()
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch last updated data'
            ], 500);
            
        } catch (\Exception $e) {
            Log::error('Mobadraft API Error (last_updated): ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Service temporarily unavailable'
            ], 503);
        }
    }
    
    /**
     * Get heroes with tier data from mobadraft
     */
    public function getHeroes(Request $request)
    {
        try {
            $rank = $request->query('rank', null);
            $mode = $request->query('mode', 'ranked'); // ranked or esports
            
            // Create cache key based on parameters
            $cacheKey = "mobadraft_heroes_{$mode}" . ($rank ? "_{$rank}" : '');
            
            // Check cache first (cache for 1 hour)
            $cachedData = Cache::get($cacheKey);
            if ($cachedData) {
                return response()->json([
                    'success' => true,
                    'data' => $cachedData,
                    'cached' => true,
                    'timestamp' => now()->toISOString()
                ]);
            }
            
            // Build URL with parameters
            $url = $this->baseUrl . '/heroes';
            $params = [];
            if ($rank) {
                $params['rank'] = $rank;
            }
            if ($mode) {
                $params['mode'] = $mode;
            }
            
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            
            $response = Http::timeout(15)->get($url);
            
            if ($response->successful()) {
                $data = $response->json();
                
                // Cache the response for 1 hour
                Cache::put($cacheKey, $data, 3600);
                
                return response()->json([
                    'success' => true,
                    'data' => $data,
                    'cached' => false,
                    'timestamp' => now()->toISOString()
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch heroes data'
            ], 500);
            
        } catch (\Exception $e) {
            Log::error('Mobadraft API Error (heroes): ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Service temporarily unavailable'
            ], 503);
        }
    }
    
    /**
     * Get tournaments data from mobadraft
     */
    public function getTournaments()
    {
        try {
            $cacheKey = 'mobadraft_tournaments';
            
            // Check cache first (cache for 2 hours)
            $cachedData = Cache::get($cacheKey);
            if ($cachedData) {
                return response()->json([
                    'success' => true,
                    'data' => $cachedData,
                    'cached' => true,
                    'timestamp' => now()->toISOString()
                ]);
            }
            
            $response = Http::timeout(10)->get($this->baseUrl . '/tournaments');
            
            if ($response->successful()) {
                $data = $response->json();
                
                // Cache the response for 2 hours
                Cache::put($cacheKey, $data, 7200);
                
                return response()->json([
                    'success' => true,
                    'data' => $data,
                    'cached' => false,
                    'timestamp' => now()->toISOString()
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tournaments data'
            ], 500);
            
        } catch (\Exception $e) {
            Log::error('Mobadraft API Error (tournaments): ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Service temporarily unavailable'
            ], 503);
        }
    }
    
    /**
     * Get tier list data (processed heroes with tiers)
     */
    public function getTierList(Request $request)
    {
        try {
            $mode = $request->query('mode', 'ranked');
            $rank = $request->query('rank', 9); // Default to Mythical Glory
            
            $cacheKey = "mobadraft_tier_list_{$mode}_{$rank}";
            
            // Check cache first (cache for 30 minutes)
            $cachedData = Cache::get($cacheKey);
            if ($cachedData) {
                return response()->json([
                    'success' => true,
                    'data' => $cachedData,
                    'cached' => true,
                    'timestamp' => now()->toISOString()
                ]);
            }
            
            // Fetch heroes data
            $heroesResponse = Http::timeout(15)->get($this->baseUrl . '/heroes', [
                'rank' => $rank,
                'mode' => $mode
            ]);
            
            if (!$heroesResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch tier data'
                ], 500);
            }
            
            $heroesData = $heroesResponse->json();
            
            // Process the data into tier format
            $tierData = $this->processTierData($heroesData, $mode);
            
            // Cache the processed data for 30 minutes
            Cache::put($cacheKey, $tierData, 1800);
            
            return response()->json([
                'success' => true,
                'data' => $tierData,
                'cached' => false,
                'timestamp' => now()->toISOString()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Mobadraft API Error (tier_list): ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Service temporarily unavailable'
            ], 503);
        }
    }
    
    /**
     * Process raw heroes data into tier format
     */
    private function processTierData($heroesData, $mode)
    {
        // This is a placeholder - you'll need to adjust based on actual mobadraft API response structure
        $tiers = [
            'S' => [],
            'A' => [],
            'B' => []
        ];
        
        // Process heroes based on their tier information
        if (isset($heroesData['heroes']) && is_array($heroesData['heroes'])) {
            foreach ($heroesData['heroes'] as $hero) {
                $tier = $hero['tier'] ?? 'B'; // Default to B tier if not specified
                if (isset($tiers[$tier])) {
                    $tiers[$tier][] = $hero['name'];
                }
            }
        }
        
        return [
            'mode' => $mode,
            'tiers' => $tiers,
            'last_updated' => $heroesData['last_updated'] ?? now()->toISOString()
        ];
    }
}
