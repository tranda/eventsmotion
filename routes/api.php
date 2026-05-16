<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\AthleteController;
use App\Http\Controllers\API\ClubController;
use App\Http\Controllers\API\EventController;
use App\Http\Controllers\API\DisciplineController;
use App\Http\Controllers\API\TeamController;
use App\Http\Controllers\API\CrewController;
use App\Http\Controllers\API\CrewAthleteController;
use App\Http\Controllers\API\RaceResultController;
use App\Http\Controllers\API\QrCodeController;
use App\Http\Controllers\API\FileController;
use App\Http\Controllers\API\PublicController;
use App\Http\Controllers\API\ApiKeyController;
use App\Http\Controllers\API\PasswordResetController;
use App\Http\Controllers\API\DatabaseBackupController;
use App\Http\Controllers\API\ScheduleConfigController;
use App\Http\Controllers\API\ScheduleGenerationController;
use App\Http\Controllers\API\DisciplineProgressionController;
use App\Http\Controllers\API\CrewSeedController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('login', [AuthController::class, 'authenticate']);
Route::middleware('auth:sanctum')->post('logout', [AuthController::class, 'logout']);

// Password Reset Routes
Route::post('forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('reset-password', [PasswordResetController::class, 'resetPassword']);
// Route::post('register', [UserController::class, 'register']);
Route::middleware('auth:sanctum')->get('users', [UserController::class, 'getUsers']);
Route::middleware('auth:sanctum')->get('users/{userId}', [UserController::class, 'getUser']);
Route::middleware('auth:sanctum')->post('users', [UserController::class, 'createUser']);
Route::middleware('auth:sanctum')->put('users/{userId}', [UserController::class, 'updateUser']);
Route::middleware('auth:sanctum')->delete('users/{userId}', [UserController::class, 'deleteUser']);
Route::middleware('auth:sanctum')->put('userPassword/{userId}', [UserController::class, 'updatePassword']);

Route::middleware('auth:sanctum')->get('athletes', [AthleteController::class, 'getAthletesByClubId']);
Route::middleware('auth:sanctum')->get('athletesTeam', [AthleteController::class, 'getAthletesByTeamId']);
Route::middleware('auth:sanctum')->get('athletesAll', [AthleteController::class, 'getAllAthletes']);
Route::middleware('auth:sanctum')->get('athletesAllClubs', [AthleteController::class, 'getAllClubAthletes']);
Route::middleware('auth:sanctum')->get('eligibleAthletes', [AthleteController::class, 'getAthletesEligibleForCrewId']);
Route::middleware('auth:sanctum')->get('eligibleCombinedAthletes', [AthleteController::class, 'getAthletesEligibleForCombinedCrewId']);
Route::middleware('auth:sanctum')->post('athletes', [AthleteController::class, 'createAthlete']);
Route::middleware('auth:sanctum')->post('athletesImport', [AthleteController::class, 'importAthletes']);
Route::middleware('auth:sanctum')->put('athletes/{athleteId}', [AthleteController::class, 'updateAthlete']);
Route::middleware('auth:sanctum')->post('athleteCertificates/{athleteId}', [AthleteController::class, 'updateCertificate']);
Route::middleware('auth:sanctum')->delete('athletes/{athleteId}', [AthleteController::class, 'deleteAthlete']);
Route::middleware('auth:sanctum')->put('athletesDetail', [AthleteController::class, 'updateAthleteDetail']);

Route::get('clubs', [ClubController::class, 'getAllClubs']);
Route::get('clubsAdel', [ClubController::class, 'getAllClubsForAdel']);
Route::middleware('auth:sanctum')->get('clubDetails', [ClubController::class, 'getClubDetails']);
Route::post('clubAndUser', [ClubController::class, 'createClubAndUser']);
Route::middleware(['auth:sanctum', 'admin'])->post('clubs', [ClubController::class, 'createClub']);
Route::middleware(['auth:sanctum', 'admin'])->put('clubs/{id}', [ClubController::class, 'updateClub']);
Route::middleware(['auth:sanctum', 'admin'])->delete('clubs/{id}', [ClubController::class, 'deleteClub']);

// Event Routes - Public access for caching before login
Route::get('events', [EventController::class, 'getAllEvents']);

// Event CRUD routes (Admin only)
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('events/list', [EventController::class, 'index']);
    Route::get('event/{id}', [EventController::class, 'show']);
    Route::post('event', [EventController::class, 'store']);
    Route::put('event/{id}', [EventController::class, 'update']);
    Route::delete('event/{id}', [EventController::class, 'destroy']);

    // API Key Management routes (Admin only)
    Route::get('api-keys', [ApiKeyController::class, 'index']);
    Route::get('api-keys/{id}', [ApiKeyController::class, 'show']);
    Route::post('api-keys', [ApiKeyController::class, 'store']);
    Route::put('api-keys/{id}', [ApiKeyController::class, 'update']);
    Route::delete('api-keys/{id}', [ApiKeyController::class, 'destroy']);

    // Database Backup routes (Admin only)
    Route::get('backups', [DatabaseBackupController::class, 'index']);
    Route::post('backups', [DatabaseBackupController::class, 'store']);
    Route::post('backups/restore', [DatabaseBackupController::class, 'restore']);
    Route::delete('backups', [DatabaseBackupController::class, 'destroy']);
    Route::get('backups/download', [DatabaseBackupController::class, 'download']);
});

// Discipline Routes - Public access for listing, admin access for CRUD
Route::get('disciplinesAll', [DisciplineController::class, 'getAllDisciplines']);
Route::middleware('auth:sanctum')->get('disciplines', [DisciplineController::class, 'getDisciplinesForClub']);
Route::middleware('auth:sanctum')->get('disciplinesCombined', [DisciplineController::class, 'getDisciplinesForCombinedClubs']);
Route::middleware('auth:sanctum')->get('teamDisciplines', [DisciplineController::class, 'getDisciplinesForTeam']);

// Discipline CRUD routes (Admin only)
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('discipline/{id}', [DisciplineController::class, 'show']);
    Route::post('discipline', [DisciplineController::class, 'store']);
    Route::put('discipline/{id}', [DisciplineController::class, 'update']);
    Route::delete('discipline/{id}', [DisciplineController::class, 'destroy']);
});

Route::get('teamsAll', [TeamController::class, 'getAllTeams']);
Route::middleware('auth:sanctum')->get('teams', [TeamController::class, 'getTeamsForClub']);
Route::middleware('auth:sanctum')->get('teamsForDiscipline', [TeamController::class, 'getTeamsForDiscipline']);
Route::middleware('auth:sanctum')->post('team', [TeamController::class, 'createTeam']);
Route::middleware(['auth:sanctum', 'admin'])->delete('teams/{id}', [TeamController::class, 'deleteTeam']);

Route::get('crewsAll', [CrewController::class, 'getAllCrews']);
// Route::middleware('auth:sanctum')->get('crews', [CrewController::class, 'getCrewsByTeamId']);
Route::middleware('auth:sanctum')->get('crews', [CrewController::class, 'getCrewsForClub']);
Route::middleware('auth:sanctum')->post('registercrew', [CrewController::class, 'register']);
Route::middleware('auth:sanctum')->post('registercrews', [CrewController::class, 'registerMulti']);
Route::middleware('auth:sanctum')->post('unregistercrew', [CrewController::class, 'unregister']);

Route::middleware('auth:sanctum')->post('crewathletes', [CrewAthleteController::class, 'createCrewAthlete']);
Route::middleware('auth:sanctum')->get('crewathletes', [CrewAthleteController::class, 'getCrewAthletesByCrewId']);
Route::middleware('auth:sanctum')->put('crewathletes/{crewathleteId}', [CrewAthleteController::class, 'updateCrewAthlete']);
Route::middleware('auth:sanctum')->delete('crewathletes/{crewathleteId}', [CrewAthleteController::class, 'deleteCrewAthlete']);

// Race Results Routes
// Secure race data import endpoint - requires API key with specific permission
Route::middleware('apikey:races.bulk-update')->post('race-results/bulk-update', [RaceResultController::class, 'bulkUpdate']);
// Secure race plans fetch endpoint - requires API key with specific permission (same as bulk-update)
Route::middleware('apikey:races.bulk-update')->get('race-results/fetch-plans', [RaceResultController::class, 'fetchRacePlans']);
// Secure single race update endpoint by race ID - requires API key with specific permission (same as bulk-update)
Route::middleware('apikey:races.bulk-update')->post('race-results/update-single', [RaceResultController::class, 'updateSingleRaceById']);
// Enhanced import endpoint with cleanup logic for discipline-specific imports
Route::middleware('auth:sanctum')->post('race-results/bulk-import-with-cleanup', [RaceResultController::class, 'bulkImportWithCleanup']);
Route::middleware('auth:sanctum')->get('race-results', [RaceResultController::class, 'index']);
Route::middleware('auth:sanctum')->get('race-results/{id}', [RaceResultController::class, 'show']);
Route::middleware('auth:sanctum')->post('race-results', [RaceResultController::class, 'store']);
Route::middleware('auth:sanctum')->put('race-results/{id}', [RaceResultController::class, 'update']);
Route::middleware('auth:sanctum')->post('race-results/reorder', [RaceResultController::class, 'reorder']);
Route::middleware('auth:sanctum')->delete('race-results/{id}', [RaceResultController::class, 'destroy']);
Route::middleware('auth:sanctum')->get('race-results/{raceResultId}/crew-results', [RaceResultController::class, 'getCrewResults']);
Route::middleware('auth:sanctum')->post('race-results/{raceResultId}/crew-results', [RaceResultController::class, 'storeCrewResults']);
Route::middleware('auth:sanctum')->post('race-results/{raceResultId}/recalculate-positions', [RaceResultController::class, 'recalculatePositions']);

// Schedule Builder (admin only)
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    // Schedule config: lane count + days + blocks
    Route::get('events/{event}/schedule-config', [ScheduleConfigController::class, 'show']);
    Route::put('events/{event}/schedule-config', [ScheduleConfigController::class, 'update']);
    Route::post('events/{event}/event-days', [ScheduleConfigController::class, 'storeDay']);
    Route::put('event-days/{day}', [ScheduleConfigController::class, 'updateDay']);
    Route::delete('event-days/{day}', [ScheduleConfigController::class, 'destroyDay']);
    Route::post('event-days/{day}/blocks', [ScheduleConfigController::class, 'storeBlock']);
    Route::put('schedule-blocks/{block}', [ScheduleConfigController::class, 'updateBlock']);
    Route::delete('schedule-blocks/{block}', [ScheduleConfigController::class, 'destroyBlock']);

    // Per-discipline plan override + crew seeds
    Route::get('disciplines/{discipline}/progression', [DisciplineProgressionController::class, 'show']);
    Route::put('disciplines/{discipline}/progression', [DisciplineProgressionController::class, 'update']);
    Route::get('disciplines/{discipline}/race-plan-options', [DisciplineProgressionController::class, 'options']);
    Route::get('disciplines/{discipline}/crew-seeds', [CrewSeedController::class, 'show']);
    Route::put('disciplines/{discipline}/crew-seeds', [CrewSeedController::class, 'update']);
    Route::post('disciplines/{discipline}/crew-seeds/reset', [CrewSeedController::class, 'reset']);

    // Generation, regeneration, publish
    Route::post('events/{event}/schedule/generate', [ScheduleGenerationController::class, 'generate']);
    Route::post('disciplines/{discipline}/schedule/regenerate', [ScheduleGenerationController::class, 'regenerateDiscipline']);
    Route::post('disciplines/{discipline}/schedule/seed-next-round', [ScheduleGenerationController::class, 'seedNextRound']);
    Route::post('events/{event}/schedule/publish', [ScheduleGenerationController::class, 'publish']);
    Route::post('events/{event}/schedule/unpublish', [ScheduleGenerationController::class, 'unpublish']);
    Route::post('events/{event}/schedule/shift', [ScheduleGenerationController::class, 'shift']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->post('qrcode', [QrCodeController::class, 'createQRCode']);
Route::middleware('auth:sanctum')->post('qrcodeAll', [QrCodeController::class, 'createQRCodeAll']);

// File proxy endpoints for serving images with CORS support
Route::get('photos/{filename}', [FileController::class, 'getPhoto']);
Route::get('certificates/{filename}', [FileController::class, 'getCertificate']);

// Public debug endpoint for testing fetch-plans functionality (NO AUTHENTICATION)
Route::get('race-results/test-fetch-plans', [RaceResultController::class, 'testFetchPlans']);

// Public Routes - No authentication required
Route::prefix('public')->group(function () {
    // Legacy public race results endpoints (keep for backward compatibility)
    Route::get('events/{eventId}/race-results', [PublicController::class, 'getRaceResults']);
    Route::get('events/{eventId}/race-results/{raceId}', [PublicController::class, 'getRaceResultDetail']);
    
    // New public race results endpoints (matching the structure you requested)
    Route::get('race-results', [PublicController::class, 'getPublicRaceResults']);
    Route::get('race-results/{raceResultId}', [PublicController::class, 'getPublicRaceResult']);
    
    // CORS preflight handling
    Route::options('events/{eventId}/race-results', [PublicController::class, 'handleCorsOptions']);
    Route::options('events/{eventId}/race-results/{raceId}', [PublicController::class, 'handleCorsOptions']);
    Route::options('race-results', [PublicController::class, 'handleCorsOptions']);
    Route::options('race-results/{raceResultId}', [PublicController::class, 'handleCorsOptions']);
});
