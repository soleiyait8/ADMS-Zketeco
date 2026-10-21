<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Throwable;

class iclockController extends Controller
{
    public function __invoke(Request $request)
    {
    }

    // handshake
    public function handshake(Request $request)
    {
        $data = [
            'url' => json_encode($request->all()),
            'data' => $request->getContent(),
            'sn' => $request->input('SN'),
            'option' => $request->input('option'),
        ];
        DB::table('device_log')->insert($data);

        // update status device
        DB::table('devices')->updateOrInsert(
            ['no_sn' => $request->input('SN')],
            ['online' => now()]
        );

        $r = "GET OPTION FROM: {$request->input('SN')}\r\n" .
             "Stamp=9999\r\n" .
             "OpStamp=" . time() . "\r\n" .
             "ErrorDelay=60\r\n" .
             "Delay=30\r\n" .
             "ResLogDay=18250\r\n" .
             "ResLogDelCount=10000\r\n" .
             "ResLogCount=50000\r\n" .
             "TransTimes=00:00;14:05\r\n" .
             "TransInterval=1\r\n" .
             "TransFlag=1111000000\r\n" .
            //  "TimeZone=7\r\n" .
             "Realtime=1\r\n" .
             "Encrypt=0";

        return $r;
    }

    // implementasi https://docs.nufaza.com/docs/devices/zkteco_attendance/push_protocol/
    // request absensi
    public function receiveRecords(Request $request)
    {
        $content['url'] = json_encode($request->all());
        $content['data'] = $request->getContent();
        DB::table('finger_log')->insert($content);

        // Defined before the try so the catch block can always use it
        $tot = 0;

        try {
            $arr = preg_split('/\\r\\n|\\r|,|\\n/', $request->getContent());

            // operation log
            if ($request->input('table') == "OPERLOG") {
                foreach ($arr as $rey) {
                    if (isset($rey)) {
                        $tot++;
                    }
                }
                return "OK: " . $tot;
            }

            // attendance
            foreach ($arr as $rey) {
                if (empty(trim($rey))) {
                    continue;
                }

                $data = explode("\t", $rey);

                // Skip malformed lines (need at least employee_id and timestamp)
                if (count($data) < 2) {
                    continue;
                }

                $q['sn'] = $request->input('SN');
                $q['table'] = $request->input('table');
                $q['stamp'] = $request->input('Stamp');
                $q['employee_id'] = $data[0];
                $q['timestamp'] = $data[1];
                $q['status1'] = $this->validateAndFormatInteger($data[2] ?? null);
                $q['status2'] = $this->validateAndFormatInteger($data[3] ?? null);
                $q['status3'] = $this->validateAndFormatInteger($data[4] ?? null);
                $q['status4'] = $this->validateAndFormatInteger($data[5] ?? null);
                $q['status5'] = $this->validateAndFormatInteger($data[6] ?? null);
                $q['created_at'] = now();
                $q['updated_at'] = now();

                DB::table('attendances')->insert($q);
                $tot++;
            }

            return "OK: " . $tot;
        } catch (Throwable $e) {
            // error_log table only has: id, data, created_at, updated_at
            try {
                DB::table('error_log')->insert([
                    'data' => (string) $e,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (Throwable $logError) {
                // If the DB itself is the problem, don't hide the original error
                report($logError);
            }

            report($e);
            return "ERROR: " . $tot . "\n";
        }
    }

    public function test(Request $request)
    {
        $log['data'] = $request->getContent();
        DB::table('finger_log')->insert($log);
    }

    public function getrequest(Request $request)
    {
        return "OK";
    }

    private function validateAndFormatInteger($value)
    {
        return isset($value) && $value !== '' ? (int) $value : null;
    }
}
