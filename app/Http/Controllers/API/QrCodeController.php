<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Athlete;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrCodeController extends BaseController
{
    public function createQRCode(Request $request)
    {
        $user = $request->user(); // Retrieve the authenticated user

        if ($user) {
            $clubId = $user->club_id;
            if ($request->has('athlete_id')) {
                $athlete = Athlete::findOrFail($request->athlete_id);
                $storagePath = 'photos/qrcodes/';
                $fileName = 'qrcode_' . $athlete->id . '.svg';
                QrCode::size(300)->generate('{"id":' . $athlete->id . ', "club_id":' . $athlete->club_id . '}', $storagePath . $fileName);
                $athlete->qrcode = $fileName;
                $athlete->save();
            } else {
                return response()->json(['error' => 'Athlete not found'], 404);
            }

            return response()->json($athlete);
        } else {
            return response()->json(['error' => 'User not found or does not have a club'], 404);
        }
    }
    public function createQRCodeAll(Request $request)
    {
        $user = $request->user(); // Retrieve the authenticated user

        if ($user) {
            $clubId = $user->club_id;
            $athletes = Athlete::all();
            foreach ($athletes as $athlete) {
                $storagePath = 'photos/qrcodes/';
                $fileName = 'qrcode_' . $athlete->id . '.png';
                QrCode::format("png")->size(300)->generate('{"id":' . $athlete->id . ', "club_id":' . $athlete->club_id . '}', $storagePath . $fileName);
                $athlete->qrcode = $fileName;
                $athlete->save();
            }

            return response()->json(['success' => 'Created QR codes for all athletes.'], 200);
        } else {
            return response()->json(['error' => 'User not found or does not have a club'], 404);
        }
    }
}
