<?php

namespace App\Http\Controllers;

use App\Models\Serial;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

const KEY_VERIFY = 'smLobo21_ABC_KH';

class SerialController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Serial  $serial
     * @return \Illuminate\Http\Response
     */
    public function show(Serial $serial)
    {
        //
    }


    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Serial  $serial
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Serial $serial)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Serial  $serial
     * @return \Illuminate\Http\Response
     */
    public function destroy(Serial $serial)
    {
        //
    }


    public function check(Request $request)
    {
        try {
            $this->validate($request, [
                'serial' => 'required',
                'uiid'   => 'required',
                'bios'   => 'required',
            ]);

            $serial = Serial::where('serial', $request->serial)
                ->where('active', true)
                ->firstOrFail();

            if ($this->isSerialExpired($serial)) {
                throw new ValidationException('Serial has expired.');
            }

            if ($request->uiid == $serial->uiid) {
                return response([
                    'status'  => true,
                    'code'    => KEY_VERIFY,
                    'message' => 'Valid',
                    'expiry_date' => $serial->expiry_date,
                ], Response::HTTP_OK);
            }

            throw new ValidationException('Invalid Serial - Mismatched Device Unique.');
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], Response::HTTP_NON_AUTHORITATIVE_INFORMATION);
        }
    }

    public function active(Request $request)
    {
        try {
            $this->validate($request, [
                'serial'          => 'required',
                // 'uiid'            => 'required',
                // 'bios'            => 'required',
                'model'           => 'required',
                'sku'             => 'required',
                'deviceUsername'   => 'required',
            ]);

            $uiid = (str_replace(' ', '', $request->uiid)) ? $request->uiid : 'XX##UIID' . rand(1000000, 100000000);
            $bios = (str_replace(' ', '', $request->bios)) ? $request->bios : 'XX##BIOS' . rand(1000000, 100000000);

            $serial = Serial::where('serial', $request->serial)
                ->where('active', true)
                ->firstOrFail();

            if ($this->isSerialExpired($serial)) {
                throw new ValidationException('Serial has expired.');
            }

            if (!$serial->uiid) {
                DB::table('serials')->where('id', $serial->id)->update([
                    'uiid'           => $request->uiid,
                    'bios'           => $request->bios,
                    'model'          => $request->model,
                    'sku'            => $request->sku,
                    'deviceUsername' => $request->deviceUsername,
                ]);

                return response([
                    'status' => true,
                    'code'   => KEY_VERIFY,
                    'type'   => $serial->type,
                    'message' => 'Valid',
                    'expiry_date' => $serial->expiry_date,

                ], Response::HTTP_OK);
            } elseif ($request->uiid == $serial->uiid) {
                return response([
                    'status' => true,
                    'code'   => KEY_VERIFY,
                    'type'   => $serial->type,
                    'message' => 'Valid - Used Again',
                    'expiry_date' => $serial->expiry_date,
                ], Response::HTTP_OK);
            }

            throw new ValidationException('Serial already used on a different device.');
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => $e->getMessage()], Response::HTTP_OK);
        }
    }


    /**
     * Check if a serial has expired.
     *
     * @param  \App\Models\Serial  $serial
     * @return bool
     */
    private function isSerialExpired(Serial $serial)
    {
        return $serial->expiryDate && now()->greaterThan($serial->expiryDate);
    }


    // -------------for delete later-----------
    public function check_old(Request $request)
    {
        $uiid = $request->uiid;
        $bios = $request->bios;

        $serial = DB::table('serials')->where('serial', $request->serial)->where('active', true)->first();
        if ($serial) {
            $identifiers =  json_decode($serial->identifiers);
            if ($identifiers) {
                foreach ($identifiers as $identifier) {
                    if ($identifier->uiid == $uiid || $identifier->bios == $bios) {
                        return response(['status' => true, 'message' => 'Valid'], Response::HTTP_OK);
                    }
                }
            }
        }

        return response(['status' => false, 'message' => 'Invalid Serial - Expired licence'], Response::HTTP_NON_AUTHORITATIVE_INFORMATION);
    }

    public function active_old(Request $request)
    {
        $uiid = (str_replace(' ', '', $request->uiid)) ? $request->uiid : 'XX##UIID' . rand(1000000, 100000000);
        $bios = (str_replace(' ', '', $request->bios)) ? $request->bios : 'XX##BIOS' . rand(1000000, 100000000);

        $serial =  DB::table('serials')->where('serial', $request->serial)->where('active', true)->first();

        if ($serial) {
            $maxUsers = $serial->maxUsers;
            $identifiers =  json_decode($serial->identifiers);

            $identifier = [
                'uiid' => $uiid,
                'bios' => $bios,
                'model' => $request->model,
                'sku' => $request->sku
            ];

            // Step.1 If Serial status was not used on any device
            if (!$identifiers && $maxUsers > 0) {
                $identifiers = json_encode(array($identifier));
                DB::table('serials')->where('id', $serial->id)->update(['identifiers' => $identifiers]);
                return response(['status' => true, 'message' => 'Valid'], Response::HTTP_OK);
            }

            //Step.2 - Check If The Same Device try to insert the serial again
            ///Check If this device founded => no action . onley activate
            $founded = false;
            foreach ($identifiers as $e) {
                if ($e->uiid == $uiid || $e->uiid == $bios) {
                    $founded = true;
                }
            }
            if ($founded) {
                return response(['status' => true, 'message' => 'Valid - Used Again'], Response::HTTP_OK);
            } else if (!$founded && count($identifiers) < $maxUsers) {
                $identifiers[] = $identifier;
                $identifiers = json_encode($identifiers);
                DB::table('serials')->where('id', $serial->id)->update(['identifiers' => $identifiers]);
                return response(['status' => true, 'message' => 'Valid 1'], Response::HTTP_OK);
            }
            return response(['status' => false, 'message' => 'Serial already used'], Response::HTTP_ALREADY_REPORTED);
        }
        return response(['status' => false, 'message' => 'Invalid Serial'], Response::HTTP_OK);
    }
}
