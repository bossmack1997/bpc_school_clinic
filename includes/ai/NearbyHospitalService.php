<?php
/**
 * NearbyHospitalService.php
 * ------------------------------------------------------------------
 * Handles legitimate emergency healthcare facility and hospital lookup
 * based on the user's real-time browser GPS coordinates.
 * 
 * Features:
 *   - Precise Haversine distance calculation
 *   - Verified hospital and clinic registry with contact info & capabilities
 *   - Clean Google Maps direct navigation URLs
 *   - Structured context generation for MICA AI prompts
 *   - Rule-based factual fallback responses when offline/degraded
 * ------------------------------------------------------------------
 */

class NearbyHospitalService
{
    /**
     * Verified Healthcare Facilities Registry (Bulacan & Vicinity)
     */
    private static array $facilities = [
        [
            'name' => 'Bulacan Medical Center (BMC)',
            'type' => 'Provincial Tertiary Hospital / Trauma Center (Public)',
            'address' => 'Capitol Compound, Mojon / Guinhawa, Malolos City, Bulacan',
            'latitude' => 14.8519,
            'longitude' => 120.8173,
            'emergency_phone' => '(044) 791-0566 / (044) 791-0570',
            'services' => '24/7 Emergency Room, Trauma Care, ICU, Blood Bank, Surgery',
            'level' => 'Level 3 Tertiary',
        ],
        [
            'name' => 'Sacred Heart Hospital of Malolos',
            'type' => 'Tertiary General Hospital (Private)',
            'address' => 'McArthur Highway, Guinhawa, Malolos City, Bulacan',
            'latitude' => 14.8562,
            'longitude' => 120.8135,
            'emergency_phone' => '(044) 796-0123 / (044) 791-2365',
            'services' => '24/7 Emergency Room, Ambulance, Diagnostic Imaging, ICU',
            'level' => 'Level 2 / Tertiary',
        ],
        [
            'name' => 'Santos General Hospital of Malolos',
            'type' => 'General Hospital (Private)',
            'address' => 'F. Estrella St., Poblacion, Malolos City, Bulacan',
            'latitude' => 14.8436,
            'longitude' => 120.8114,
            'emergency_phone' => '(044) 791-0268 / (044) 791-0270',
            'services' => '24/7 Emergency Room, Laboratory, Pharmacy, Inpatient',
            'level' => 'Level 2',
        ],
        [
            'name' => 'Malolos Maternity Hospital and Eye Center',
            'type' => 'Maternity & Specialty Hospital (Private)',
            'address' => 'McArthur Highway, Sumapang Matanda, Malolos City, Bulacan',
            'latitude' => 14.8389,
            'longitude' => 120.8225,
            'emergency_phone' => '(044) 791-6278',
            'services' => '24/7 Maternal Emergency, Ophthalmology, Pediatrics',
            'level' => 'Specialty Hospital',
        ],
        [
            'name' => 'Graman Medical Center',
            'type' => 'General Hospital (Private)',
            'address' => 'Paseo del Congreso, San Gabriel, Malolos City, Bulacan',
            'latitude' => 14.8485,
            'longitude' => 120.8142,
            'emergency_phone' => '(044) 791-1188',
            'services' => '24/7 Emergency Assistance, Diagnostics, Outpatient/Inpatient',
            'level' => 'Level 1 / Secondary',
        ],
        [
            'name' => 'BPC School Clinic (Campus Medical Center)',
            'type' => 'School Healthcare Station (Institutional)',
            'address' => 'BPC Main Campus, Bulihan, Malolos City, Bulacan',
            'latitude' => 14.8527,
            'longitude' => 120.8160,
            'emergency_phone' => '(044) 791-3088 (Clinic Nurse Station)',
            'services' => 'First Aid, Vitals Monitoring, Emergency SOS Response, Medicine Dispensing',
            'level' => 'Primary School Clinic',
        ],
        [
            'name' => 'Plaridel County Hospital',
            'type' => 'District Public Hospital',
            'address' => 'Banga 1st, Plaridel, Bulacan',
            'latitude' => 14.8872,
            'longitude' => 120.8614,
            'emergency_phone' => '(044) 795-0120',
            'services' => '24/7 Emergency Room, General Medicine, Pediatrics',
            'level' => 'Level 1 Public Hospital',
        ],
        [
            'name' => 'Guiguinto Municipal Infirmary Hospital',
            'type' => 'Community Hospital (Public)',
            'address' => 'Poblacion, Guiguinto, Bulacan',
            'latitude' => 14.8321,
            'longitude' => 120.8805,
            'emergency_phone' => '(044) 794-0322',
            'services' => '24/7 Emergency Care, Triage, Minor Surgery, Pharmacy',
            'level' => 'Infirmary / Primary',
        ],
        [
            'name' => 'Ace Medical Center Baliwag',
            'type' => 'Tertiary Medical Center (Private)',
            'address' => 'DRT Highway, Pinagbarilan, Baliwag, Bulacan',
            'latitude' => 14.9542,
            'longitude' => 120.9021,
            'emergency_phone' => '(044) 896-1234 / 0917-812-4223',
            'services' => '24/7 ER, Cardiac Catheterization, Stroke Unit, ICU, Trauma',
            'level' => 'Level 3 Tertiary',
        ],
        [
            'name' => 'Bocaue Municipal Hospital (Joni Villanueva General Hospital)',
            'type' => 'General Public Hospital',
            'address' => 'Igulot, Bocaue, Bulacan',
            'latitude' => 14.8021,
            'longitude' => 120.9412,
            'emergency_phone' => '(044) 896-2000',
            'services' => '24/7 Emergency Room, Inpatient, Clinical Lab, X-Ray',
            'level' => 'Level 1 Public Hospital',
        ],
        [
            'name' => 'Meycauayan Doctors Hospital',
            'type' => 'Tertiary Private Hospital',
            'address' => 'I.S. Pavillion Commercial Complex, Km. 18 McArthur Highway, Banga, Meycauayan, Bulacan',
            'latitude' => 14.7381,
            'longitude' => 120.9602,
            'emergency_phone' => '(044) 769-6330 / (044) 815-1888',
            'services' => '24/7 Emergency Room, Critical Care, Dialysis, Diagnostic Lab',
            'level' => 'Level 2 / Tertiary',
        ],
        [
            'name' => 'QualiMed Hospital - Altaraza (San Jose del Monte)',
            'type' => 'Tertiary Hospital (Private)',
            'address' => 'Quirino Highway, Tungkong Mangga, City of San Jose del Monte, Bulacan',
            'latitude' => 14.7891,
            'longitude' => 121.0745,
            'emergency_phone' => '(044) 913-9000',
            'services' => '24/7 Emergency Room, Trauma, Surgery, Radiology, ICU',
            'level' => 'Level 2 / Tertiary',
        ],
        [
            'name' => 'Calumpit District Hospital',
            'type' => 'District Public Hospital',
            'address' => 'San Marcos, Calumpit, Bulacan',
            'latitude' => 14.9125,
            'longitude' => 120.7681,
            'emergency_phone' => '(044) 913-1456',
            'services' => '24/7 Emergency Care, General Medicine, Maternal Health',
            'level' => 'Level 1 Public Hospital',
        ],
        [
            'name' => 'Jose B. Lingad Memorial General Hospital (JBLMGH)',
            'type' => 'Regional Apex Trauma Center (Public)',
            'address' => 'Dolores, City of San Fernando, Pampanga',
            'latitude' => 15.0345,
            'longitude' => 120.6865,
            'emergency_phone' => '(045) 961-3544 / (045) 961-3921',
            'services' => 'Apex Level 3 Regional Trauma Center, Advanced ICU, Burn Unit, Poison Control',
            'level' => 'Level 3 Apex Tertiary',
        ],
    ];

    /**
     * Calculate Great-Circle Distance between two coordinate points in kilometers.
     */
    public static function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadiusKm * $c, 2);
    }

    /**
     * Format distance into human-friendly string (e.g. "450 m away" or "2.3 km away").
     */
    public static function formatDistance(float $km): string
    {
        if ($km < 1.0) {
            $meters = round($km * 1000);
            return "{$meters} meters away";
        }
        return sprintf("%.1f km away", $km);
    }

    /**
     * Find nearest emergency facilities from given coordinates.
     * 
     * @param float $lat User Latitude
     * @param float $lng User Longitude
     * @param int $limit Number of closest facilities to return
     * @return array
     */
    public static function findNearest(float $lat, float $lng, int $limit = 4): array
    {
        $results = [];

        foreach (self::$facilities as $facility) {
            $dist = self::calculateDistance($lat, $lng, (float)$facility['latitude'], (float)$facility['longitude']);
            $fLat = (float)$facility['latitude'];
            $fLng = (float)$facility['longitude'];
            
            // Build direct Google Maps navigation URL
            $mapsUrl = "https://www.google.com/maps/dir/?api=1&destination={$fLat},{$fLng}";

            $results[] = array_merge($facility, [
                'distance_km' => $dist,
                'distance_formatted' => self::formatDistance($dist),
                'maps_url' => $mapsUrl,
            ]);
        }

        // Sort ascending by distance
        usort($results, function ($a, $b) {
            return $a['distance_km'] <=> $b['distance_km'];
        });

        return array_slice($results, 0, $limit);
    }

    /**
     * Generate structured location context string for PromptBuilder.
     */
    public static function buildLocationContext(
        ?float $lat,
        ?float $lng,
        ?string $accuracy = null,
        ?string $timestamp = null,
        string $status = 'unavailable',
        bool $isLowAccuracy = false,
        ?float $accuracyMeters = null
    ): string {
        $out = "## USER REAL-TIME BROWSER LOCATION CONTEXT\n";

        // Check if accuracy is numerically > 1000 meters
        if ($accuracyMeters === null && $accuracy !== null) {
            $numAcc = (float)preg_replace('/[^0-9.]/', '', $accuracy);
            if ($numAcc > 0) $accuracyMeters = $numAcc;
        }
        if ($accuracyMeters !== null && $accuracyMeters > 1000) {
            $isLowAccuracy = true;
        }

        // Check staleness (> 10 minutes)
        $isStale = false;
        if ($timestamp) {
            $tsUnix = strtotime($timestamp);
            if ($tsUnix !== false && (time() - $tsUnix > 600)) {
                $isStale = true;
            }
        }

        if ($lat !== null && $lng !== null && $status === 'granted') {
            if ($isLowAccuracy) {
                $accDesc = $accuracyMeters ? (round($accuracyMeters / 1000, 1) . ' km') : ($accuracy ?: 'low accuracy');
                $out .= "- **Location Status**: AVAILABLE BUT LOW ACCURACY (Coarse Browser GPS ~±{$accDesc})\n";
                $out .= "- **Latitude**: " . number_format($lat, 6) . "\n";
                $out .= "- **Longitude**: " . number_format($lng, 6) . "\n";
                $out .= "- **Accuracy**: ±{$accDesc}\n";
                $out .= "- **Location Timestamp**: " . ($timestamp ?: date('Y-m-d H:i:s')) . ($isStale ? " (OUTDATED/STALE)" : "") . "\n\n";
                $out .= "### CRITICAL INSTRUCTION FOR LOW GPS ACCURACY:\n";
                $out .= "- Location is AVAILABLE (granted by user with coarse accuracy ~±{$accDesc}).\n";
                $out .= "- If asked 'Do you know my location?' or 'Where am I?', confirm: \"Yes, I have access to your location through your browser (approximate accuracy ±{$accDesc}).\"\n";
                $out .= "- If asked for nearest hospitals, provide the closest facilities and mention the accuracy is approximate.\n\n";
            } else {
                $out .= "- **Location Status**: AVAILABLE (Precise Browser GPS)\n";
                $out .= "- **Latitude**: " . number_format($lat, 6) . "\n";
                $out .= "- **Longitude**: " . number_format($lng, 6) . "\n";
                if ($accuracy) {
                    $out .= "- **Accuracy**: " . htmlspecialchars($accuracy) . "\n";
                }
                $out .= "- **Location Timestamp**: " . ($timestamp ?: date('Y-m-d H:i:s')) . ($isStale ? " (OUTDATED - user may need to refresh)" : "") . "\n\n";
                $out .= "### INSTRUCTION FOR PRECISE GPS ACCURACY:\n";
                $out .= "- Location is AVAILABLE (granted by user).\n";
                $out .= "- If asked 'Do you know my location?' or 'Where am I?', confirm: \"Yes, I have access to your current location through your browser.\"\n\n";

                if ($isStale) {
                    $out .= "- **Note on Staleness**: Location data is older than 10 minutes. Mention to the user that this is based on their last recorded location.\n\n";
                }

                $nearest = self::findNearest($lat, $lng, 4);
                $out .= "### Nearby Emergency Healthcare Facilities (Calculated from User's Current GPS):\n";
                foreach ($nearest as $idx => $fac) {
                    $num = $idx + 1;
                    $out .= "{$num}. **{$fac['name']}** ({$fac['distance_formatted']})\n";
                    $out .= "   - Address: {$fac['address']}\n";
                    $out .= "   - Type & Level: {$fac['type']} ({$fac['level']})\n";
                    $out .= "   - Emergency Contact: {$fac['emergency_phone']}\n";
                    $out .= "   - Key Services: {$fac['services']}\n";
                    $out .= "   - Direct Directions: [Open Google Maps]({$fac['maps_url']})\n\n";
                }

                $out .= "### Real Emergency Hotlines (Bulacan & National):\n";
                $out .= "- **National Emergency**: 911\n";
                $out .= "- **Bulacan Rescue 911 (PDRRMO)**: (044) 791-0566 / 0998-580-0911\n";
                $out .= "- **Philippine Red Cross Bulacan**: (044) 791-2364 / 143\n";
                $out .= "- **BPC School Clinic**: (044) 791-3088\n";
            }
        } elseif ($status === 'denied') {
            $out .= "- **Location Status**: BLOCKED / PERMISSION DENIED (No coordinates available)\n";
            $out .= "- Note: The user has denied or blocked location permission in their browser settings.\n";
            $out .= "- Guidance: Advise the user: \"Location access is blocked. Please allow location permission for this website in your browser settings.\"\n";
        } elseif ($status === 'timeout') {
            $out .= "- **Location Status**: TIMEOUT (No coordinates available)\n";
            $out .= "- Guidance: Advise the user: \"Location request timed out. Please make sure location services are enabled and try again.\"\n";
        } elseif ($status === 'unavailable') {
            $out .= "- **Location Status**: POSITION UNAVAILABLE (No coordinates available)\n";
            $out .= "- Guidance: Advise the user: \"Your device could not determine your current location. Please check your device location services and try again.\"\n";
        } elseif ($status === 'unsupported') {
            $out .= "- **Location Status**: UNSUPPORTED (No coordinates available)\n";
            $out .= "- Guidance: Advise the user: \"Your browser does not support location services.\"\n";
        } else {
            $out .= "- **Location Status**: NOT YET GRANTED / UNREQUESTED (No coordinates available)\n";
            $out .= "- **Critical Instruction**: You do NOT have the user's location. If the user asks if you have their location or asks for nearby places, state clearly: \"I don't have access to your current location yet. I can help find nearby hospitals and facilities once you allow location access in your browser by clicking the microphone or location button.\"\n";
        }

        return $out;
    }

    /**
     * Generate an exact rule-based reply for nearby hospitals when offline or fallback mode is active.
     */
    public static function buildFallbackReply(
        ?float $lat,
        ?float $lng,
        string $status,
        string $userName = 'there',
        ?string $accuracy = null,
        ?string $timestamp = null,
        bool $isLowAccuracy = false,
        ?float $accuracyMeters = null,
        string $message = ''
    ): string {
        $lowerMsg = mb_strtolower(trim($message));

        // Calculate numeric accuracy if given string
        if ($accuracyMeters === null && $accuracy !== null) {
            $numAcc = (float)preg_replace('/[^0-9.]/', '', $accuracy);
            if ($numAcc > 0) $accuracyMeters = $numAcc;
        }
        if ($accuracyMeters !== null && $accuracyMeters > 1000) {
            $isLowAccuracy = true;
        }

        if ($lat !== null && $lng !== null && $status === 'granted') {
            // Case 1: General location status / awareness inquiry ("Do you know my location?", "Where am I?", etc.)
            if (preg_match('/\b(know|see|have|tell|check|show)\b.*\b(my location|where i am|my gps|my coordinates)\b/i', $lowerMsg) ||
                preg_match('/\b(do you know where i am|what is my location|where am i|alam mo ba location ko|nasaan ako)\b/i', $lowerMsg)) {
                $accDesc = $accuracy ? " (accuracy: ±{$accuracy})" : "";
                return "Yes, {$userName}! I have access to your current location through your browser{$accDesc}.\n\nI can help you find nearby emergency hospitals, clinic facilities, or navigate to nearby services. What facility or assistance do you need?";
            }

            // Case 2: Non-hospital search (e.g. gas stations, pharmacy, stores)
            if (preg_match('/\b(gas station|gasolinahan|petrol|gas)\b/i', $lowerMsg)) {
                $mapsSearchUrl = "https://www.google.com/maps/search/gas+station/@{$lat},{$lng},15z";
                return "I have your location coordinates, {$userName}. While my system database specifically focuses on emergency medical centers and the BPC School Clinic, you can find nearby gas stations on Google Maps here:\n\n⛽ [Search Nearby Gas Stations on Google Maps]({$mapsSearchUrl})\n\nIf you ever need medical assistance or first aid, I can also direct you to the nearest hospital.";
            }

            if (preg_match('/\b(pharmacy|drugstore|botika|mercury drug)\b/i', $lowerMsg)) {
                $mapsSearchUrl = "https://www.google.com/maps/search/pharmacy/@{$lat},{$lng},15z";
                return "I have your location coordinates, {$userName}. You can find nearby pharmacies and drugstores on Google Maps here:\n\n💊 [Search Nearby Pharmacies on Google Maps]({$mapsSearchUrl})\n\nNote: For basic over-the-counter medicine on campus, you can also check our BPC School Clinic inventory.";
            }

            if (preg_match('/\b(convenience store|store|grocery|7-eleven|seven eleven|supermarket)\b/i', $lowerMsg)) {
                $mapsSearchUrl = "https://www.google.com/maps/search/stores/@{$lat},{$lng},15z";
                return "I have your location coordinates, {$userName}. You can search for nearby stores on Google Maps here:\n\n🏪 [Search Nearby Stores on Google Maps]({$mapsSearchUrl})";
            }

            // Low accuracy check (> 1000m) for medical facilities
            if ($isLowAccuracy) {
                $accKm = $accuracyMeters ? round($accuracyMeters / 1000, 1) : null;
                $accStr = $accKm ? " (accuracy is approximately ±{$accKm} km)" : "";
                return "I can access your location, {$userName}, but the GPS accuracy is currently too low{$accStr} to reliably determine the nearest hospital. Please enable device location services and try again.\n\nIn the meantime, if this is an urgent situation, you can immediately contact:\n• **National Emergency Hotline**: 911\n• **Bulacan Rescue (PDRRMO)**: (044) 791-0566\n• **BPC School Clinic Nurse Station**: (044) 791-3088";
            }

            $nearest = self::findNearest($lat, $lng, 3);
            
            $reply = "I have your current location, {$userName}. Here are the closest verified emergency medical facilities near you:\n\n";
            foreach ($nearest as $idx => $fac) {
                $num = $idx + 1;
                $reply .= "{$num}. **{$fac['name']}** — *{$fac['distance_formatted']}*\n";
                $reply .= "   📍 {$fac['address']}\n";
                $reply .= "   📞 Emergency: **{$fac['emergency_phone']}**\n";
                $reply .= "   🏥 {$fac['services']}\n";
                $reply .= "   🧭 [Navigate via Google Maps]({$fac['maps_url']})\n\n";
            }
            $reply .= "⚠️ **For life-threatening emergencies**, call **911** or **Bulacan Rescue (044) 791-0566** immediately, or tap the **SOS button** in the clinic system to alert our nurses.";
            return $reply;
        }

        if ($status === 'denied') {
            return "Location access is blocked, {$userName}. Please allow location permission for this website in your browser settings so I can find nearby healthcare facilities and places.\n\nIn the meantime, you can reach:\n• **National Emergency**: 911\n• **Bulacan Rescue**: (044) 791-0566\n• **BPC School Clinic**: (044) 791-3088";
        }

        if ($status === 'timeout') {
            return "Location request timed out, {$userName}. Please make sure location services are enabled and try again.\n\nIf you need urgent medical help, call **911** or **Bulacan Rescue at (044) 791-0566**.";
        }

        if ($status === 'unavailable') {
            return "Your device could not determine your current location, {$userName}. Please check your device's location settings or Wi-Fi connection and try again.\n\nEmergency contacts:\n• **National Emergency**: 911\n• **Bulacan Rescue**: (044) 791-0566\n• **BPC School Clinic**: (044) 791-3088";
        }

        if ($status === 'unsupported') {
            return "Your browser does not support location services, {$userName}.\n\nFor emergency assistance, please call **911** or contact the **BPC School Clinic at (044) 791-3088**.";
        }

        return "I can help find nearby hospitals and facilities, {$userName}, but I need your location first. Please allow location access in your browser by clicking the microphone or location button.";
    }
}

