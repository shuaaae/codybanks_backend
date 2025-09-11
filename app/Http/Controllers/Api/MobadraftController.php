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
            
            // Build URL with parameters based on mode
            $apiEndpoint = $mode === 'esports' ? '/tournament_statistics' : '/heroes';
            $url = $this->baseUrl . $apiEndpoint;
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
            
            Log::info("MobadraftController: Getting tier list for mode={$mode}, rank={$rank}");
            
            $cacheKey = "mobadraft_tier_list_{$mode}_{$rank}";
            
            // Check cache first (cache for 30 minutes)
            $cachedData = Cache::get($cacheKey);
            if ($cachedData) {
                Log::info("MobadraftController: Returning cached data");
                return response()->json([
                    'success' => true,
                    'data' => $cachedData,
                    'cached' => true,
                    'timestamp' => now()->toISOString()
                ]);
            }
            
            Log::info("MobadraftController: No cached data, fetching from mobadraft API");
            
            // Fetch real data from Mobadraft API based on mode
            $apiEndpoint = $mode === 'esports' ? '/tournament_statistics' : '/heroes';
            $response = Http::timeout(15)->get($this->baseUrl . $apiEndpoint);
            
            if ($response->successful()) {
                $data = $response->json();
                
                // Process the real data into tier format
                $processedData = $this->processRealTierData($data, $mode);
                
                // Cache the processed data for 30 minutes
                Cache::put($cacheKey, $processedData, 1800);
                
                Log::info("MobadraftController: Returning real data for mode={$mode}");
                
                return response()->json([
                    'success' => true,
                    'data' => $processedData,
                    'cached' => false,
                    'mock' => false,
                    'timestamp' => now()->toISOString()
                ]);
            } else {
                // Fallback to mock data if API fails
                $mockTierData = $this->getMockTierData($mode);
                
                // Cache the mock data for 30 minutes
                Cache::put($cacheKey, $mockTierData, 1800);
                
                Log::info("MobadraftController: API failed, returning mock data for mode={$mode}");
                
                return response()->json([
                    'success' => true,
                    'data' => $mockTierData,
                    'cached' => false,
                    'mock' => true,
                    'timestamp' => now()->toISOString()
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Mobadraft API Error (tier_list): ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'mode' => $request->query('mode', 'ranked'),
                'rank' => $request->query('rank', 9)
            ]);
            
            // Return mock data as fallback instead of 503
            $mockTierData = $this->getMockTierData($request->query('mode', 'ranked'));
            
            return response()->json([
                'success' => true,
                'data' => $mockTierData,
                'cached' => false,
                'mock' => true,
                'error' => 'Using mock data due to API error: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ]);
        }
    }
    
    /**
     * Get mock tier data for testing
     */
    private function getMockTierData($mode)
    {
        $mockData = [
            'ranked' => [
                'S' => ['Wanwan', 'Yi Sun-shin', 'Lancelot', 'Floryn', 'Hayabusa', 'Aamon', 'Natan'],
                'A' => ['Gloo', 'Grock', 'Arlott', 'Fredrinn', 'Kalea', 'Diggie', 'Angela', 'Cici', 'Estes', 'Irithel', 'Alucard', 'Badang', 'Rafaela', 'Franco', 'Fanny', 'Zetian', 'Saber', 'Lapu-Lapu', 'Sun'],
                'B' => ['Uranus', 'Minsitthar', 'Lesley', 'Chang_e', 'Kadita', 'Hanzo', 'Alice', 'Yu Zhong', 'Belerick', 'Phoveus', 'Kimmy', 'Julian', 'Freya', 'Baxia', 'X.Borg', 'Zhuxin', 'Lolita', 'Gusion', 'Argus', 'Pharsa', 'Ixia', 'Mathilda', 'Selena', 'Tigreal', 'Hanabi', 'Granger', 'Gatotkaca', 'Khufra', 'Eudora', 'Melissa', 'Joy', 'Masha', 'Roger', 'Ruby', 'Carmilla', 'Yve', 'Helcurt', 'Moskov', 'Chou'],
                'C' => ['Natalia', 'Karina', 'Ling', 'Esmeralda', 'Harley', 'Popol and Kupa', 'Lukas', 'Chou'],
                'D' => ['Nolan', 'Suyou', 'Aamon', 'Benedetta', 'Layla', 'Miya', 'Eudora', 'Tigreal']
            ],
            'esports' => [
                'S' => ['Wanwan', 'Lancelot', 'Floryn', 'Hayabusa', 'Aamon', 'Natan', 'Gloo'],
                'A' => ['Grock', 'Arlott', 'Fredrinn', 'Kalea', 'Diggie', 'Angela', 'Cici', 'Estes', 'Irithel', 'Alucard', 'Badang', 'Rafaela', 'Franco', 'Fanny', 'Zetian', 'Saber', 'Lapu-Lapu', 'Sun', 'Yi Sun-shin'],
                'B' => ['Uranus', 'Minsitthar', 'Lesley', 'Chang_e', 'Kadita', 'Hanzo', 'Alice', 'Yu Zhong', 'Belerick', 'Phoveus', 'Kimmy', 'Julian', 'Freya', 'Baxia', 'X.Borg', 'Zhuxin', 'Lolita', 'Gusion', 'Argus', 'Pharsa', 'Ixia', 'Mathilda', 'Selena', 'Tigreal', 'Hanabi', 'Granger', 'Gatotkaca', 'Khufra', 'Eudora', 'Melissa', 'Joy', 'Masha', 'Roger', 'Ruby', 'Carmilla', 'Yve', 'Helcurt', 'Moskov', 'Chou'],
                'C' => ['Natalia', 'Karina', 'Ling', 'Esmeralda', 'Harley', 'Popol and Kupa', 'Lukas', 'Chou'],
                'D' => ['Nolan', 'Suyou', 'Aamon', 'Benedetta', 'Layla', 'Miya', 'Eudora', 'Tigreal']
            ]
        ];
        
        return [
            'mode' => $mode,
            'tiers' => $mockData[$mode] ?? $mockData['ranked'],
            'last_updated' => now()->toISOString()
        ];
    }
    
    /**
     * Process real heroes data from Mobadraft API into tier format
     */
    private function processRealTierData($apiData, $mode)
    {
        $tiers = [
            'S' => [],
            'A' => [],
            'B' => [],
            'C' => [],
            'D' => []
        ];
        
        // Log the API response structure for debugging
        Log::info("MobadraftController: Processing data for mode={$mode}", [
            'api_data_keys' => array_keys($apiData),
            'has_heroes' => isset($apiData['heroes']),
            'heroes_count' => isset($apiData['heroes']) ? count($apiData['heroes']) : 0
        ]);
        
        // Process data based on the API endpoint used
        if ($mode === 'esports') {
            // Process tournament statistics data - uses 'statistics' array instead of 'heroes'
            if (isset($apiData['statistics']) && is_array($apiData['statistics'])) {
                Log::info("MobadraftController: Processing esports data from statistics array");
                foreach ($apiData['statistics'] as $hero) {
                    if (is_array($hero) && count($hero) >= 6) {
                        $name = $hero[1]; // Hero name is at index 1
                        $tier = $hero[6]; // Tier is at index 6
                        
                        if ($tier && isset($tiers[$tier])) {
                            $tiers[$tier][] = $name;
                        }
                    }
                }
            } elseif (isset($apiData['heroes']) && is_array($apiData['heroes'])) {
                Log::info("MobadraftController: Processing esports data from heroes array");
                foreach ($apiData['heroes'] as $hero) {
                    if (is_array($hero) && count($hero) >= 6) {
                        $name = $hero[1]; // Hero name is at index 1
                        $tier = $hero[6]; // Tier is at index 6
                        
                        if ($tier && isset($tiers[$tier])) {
                            $tiers[$tier][] = $name;
                        }
                    }
                }
            } else {
                // Try alternative data structure for tournament statistics
                Log::info("MobadraftController: Trying alternative data structure for esports");
                if (isset($apiData['data']) && is_array($apiData['data'])) {
                    foreach ($apiData['data'] as $hero) {
                        if (is_array($hero) && count($hero) >= 6) {
                            $name = $hero[1]; // Hero name is at index 1
                            $tier = $hero[6]; // Tier is at index 6
                            
                            if ($tier && isset($tiers[$tier])) {
                                $tiers[$tier][] = $name;
                            }
                        }
                    }
                }
            }
        } else {
            // Process regular heroes data for ranked mode
            if (isset($apiData['heroes']) && is_array($apiData['heroes'])) {
                foreach ($apiData['heroes'] as $hero) {
                    if (is_array($hero) && count($hero) >= 6) {
                        $name = $hero[1]; // Hero name is at index 1
                        $tier = $hero[6]; // Tier is at index 6
                        
                        if ($tier && isset($tiers[$tier])) {
                            $tiers[$tier][] = $name;
                        }
                    }
                }
            }
        }
        
        Log::info("MobadraftController: Processed tiers for mode={$mode}", [
            'S_count' => count($tiers['S']),
            'A_count' => count($tiers['A']),
            'B_count' => count($tiers['B']),
            'C_count' => count($tiers['C']),
            'D_count' => count($tiers['D'])
        ]);
        
        return [
            'mode' => $mode,
            'tiers' => $tiers,
            'last_updated' => $apiData['updated_at'] ?? now()->toISOString()
        ];
    }

    /**
     * Process raw heroes data into tier format (legacy method)
     */
    private function processTierData($heroesData, $mode)
    {
        // This is a placeholder - you'll need to adjust based on actual mobadraft API response structure
        $tiers = [
            'S' => [],
            'A' => [],
            'B' => [],
            'C' => [],
            'D' => []
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
