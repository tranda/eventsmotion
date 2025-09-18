<?php

use App\Models\Crew;
use App\Models\Team;
use App\Models\Discipline;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

// class ImportCrewsCommand extends Command
// {
//     protected $signature = 'import:crews {file}';

//     protected $description = 'Import crews from a CSV or Excel file';

//     public function handle()
//     {
//         $file = $this->argument('file');

//         Excel::import(function ($rows) {
//             // Get the header row to map discipline names to column indexes
//             $headerRow = $rows->first()->toArray();

//             // Get the discipline names from column indexes 5 to 22
//             $disciplineNames = array_slice($headerRow, 5, 18);

//             foreach ($rows->skip(1) as $row) {
//                 $teamName = $row[0];

//                 // Find the team based on the name
//                 $team = Team::where('name', $teamName)->first();

//                 if ($team) {
//                     // Loop through each discipline column (column index 5 to 22)
//                     for ($i = 5; $i <= 22; $i++) {
//                         $race = $disciplineNames[$i - 5];
//                         $applied = $row[$i] === 'x';

//                         if ($applied) {
//                             // Find the discipline based on the race name
//                             $discipline = Discipline::where('name', $race)->first();

//                             if ($discipline) {
//                                 Crew::create([
//                                     'team_id' => $team->id,
//                                     'discipline_id' => $discipline->id,
//                                 ]);
//                             }
//                         }
//                     }
//                 }
//             }
//         }, $file);
//     }
// }


