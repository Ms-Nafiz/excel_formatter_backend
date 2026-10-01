<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Building;
use App\Models\Collector;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationCollectorSeeder extends Seeder
{
    /**
     * Seed Areas, Buildings, and Collectors with area assignments (No Users).
     */
    public function run(): void
    {
        // 1. Clear old pivot relations & areas
        DB::table('area_collector')->delete();
        Area::query()->delete();

        // 2. Requested Area List
        $newAreas = [
            'Banani-2',
            'Banani',
            'Canbazar-A',
            'Canbazar-E',
            'Canbazar Army-B',
            'Canbazar Army-E',
            'Canbazar Civil-B',
            'Canbazar Civil-E',
            'Mannan Line',
            'Moinul Road',
            'Office Target',
            'Mostofa Kamal',
            'Nirjhor',
            'AHQ',
            'ISPR',
            'CMH',
            'DGFI',
            'AFD',
            'Rajonigondha',
            'Seena Polly',
            'Staff Road-2',
            'Staff Road',
            'Yousuf Road',
            'Zia Koloni',
        ];

        $areaModels = [];
        foreach ($newAreas as $areaName) {
            $areaModels[strtolower($areaName)] = Area::firstOrCreate(['name' => trim($areaName)]);
        }

        // 3. Clear & Seed Building List
        Building::query()->delete();

        $newBuildings = [
            'Uttoron',
            'Uttorayon',
            'Upayon',
            'Un Complex',
            'Ujjibon',
            'Udoyon',
            'Uddipon',
            'Thana Qtr',
            'Surjotorun',
            'Surjoshikha',
            'Surjokiron',
            'Surjodhighal',
            'Sornolata',
            'Sornali',
            'Sopnonir',
            'Sopnolok',
            'Sopnochura-3',
            'Sopnochura-2',
            'Sopnochura',
            'Shantirokkhi Nibash',
            'Shadhinota Shoroni',
            'Seenanir-3',
            'Seenanir-2',
            'Seenanir',
            'Sebanir',
            'Sayashongi',
            'Rupsha',
            'Rupali Bank Qtr',
            'Rajbashor-3',
            'Proyash Qtr',
            'Projonmo',
            'Post Office',
            'Porshi',
            'Pgr',
            'Palki',
            'Palangko',
            'Nokkhotro',
            'Navy House',
            'Mess-c',
            'Mess-b',
            'Mess-a (white)',
            'Mess-a',
            'Manoshi',
            'Malotika',
            'Malobika',
            'Madhurika',
            'Kunjolata',
            'K Hossain Buliding',
            'Issb Officer\'s Mess',
            'Himadri',
            'Hamid Line',
            'Gangchill',
            'Gagri',
            'Enc\'s Complex',
            'Dolna',
            'Dipshikha',
            'Dgms',
            'Chondroprova',
            'Choitali',
            'Chayasurjo',
            'Chayasongi',
            'Chameli',
            'Canpublic Qtr',
            'Bivabori',
            'Bihongo',
            'Banalata',
            'Ashalata',
            'Arshi',
            'Anowar Qtr',
            'Alochaya',
            'Ahq Old Mess (red )',
            'Ahq Old Mess',
            'Ahq Office',
            'Ahq New Mess',
            'Agami',
            'Adomji Qtr Old',
            'Adomji Qtr New',
            'Adomji Old Qtr',
        ];

        foreach (array_unique($newBuildings) as $bName) {
            Building::create(['name' => trim($bName)]);
        }

        // 4. Seed Collectors with exact Area Assignments
        Collector::query()->delete();

        $collectorAssignments = [
            'ISPR' => ['ISPR'],
            'Mr.Eyamin' => ['Rajonigondha', 'Banani-2', 'Yousuf Road'],
            'Mr.Esrafil Hossen' => ['DGFI', 'Mostofa Kamal', 'Zia Koloni', 'Staff Road'],
            'Mr.Al-Amin' => ['Canbazar Army-B', 'Canbazar Civil-B', 'Nirjhor', 'AFD', 'Canbazar-A'],
            'Mr.Eklas' => ['Canbazar Army-E', 'Canbazar Civil-E', 'Canbazar-E'],
            'Mr.Golam Kibria' => ['Mannan Line', 'Seena Polly', 'Staff Road-2'],
            'Mr.Shimul Mahmud' => ['Banani', 'AHQ', 'Moinul Road'],
            'CMH' => ['CMH'],
            'Office' => ['Office Target'],
        ];

        foreach ($collectorAssignments as $collectorName => $assignedAreaNames) {
            $collector = Collector::create([
                'name' => trim($collectorName),
                'status' => 'active',
            ]);

            $syncIds = [];
            foreach ($assignedAreaNames as $aName) {
                $lowerKey = strtolower(trim($aName));
                if (isset($areaModels[$lowerKey])) {
                    $syncIds[] = $areaModels[$lowerKey]->id;
                }
            }

            $collector->areas()->sync($syncIds);
        }
    }
}
