<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CertificateDownload;
use App\Models\Course;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DashboardController extends Controller
{
    /**
     * Display dashboard statistics.
     */
    public function index(Request $request)
    {
        try {
            // Validate request (optional, no params needed)
            $validator = Validator::make($request->all(), []);

            if ($validator->fails()) {
                \Log::error('Validation errors:', $validator->errors()->toArray());
                return response()->json($validator->errors(), 422);
            }

            // Calculate dashboard statistics
            $stats = [
                'live_teaching' => Course::where('type', 'Live_Teaching')->count(),
                'cbt' => Course::where('type', 'CBT')->count(),
                'users' => User::count(),
                'courses' => Course::where('type', 'Course')->count(),
            ];

            // Calculate transaction analytics
            $analytics = Transaction::where('status', 'paid')
                ->selectRaw('COUNT(*) as transaction_count, SUM(total) as revenue')
                ->first();

            $stats['registrants'] = $analytics ? $analytics->transaction_count : 0;
            $stats['revenue'] = $analytics ? number_format(($analytics->revenue ?? 0), 2) : '0.00';
            $stats['certificate'] = CertificateDownload::count();

            // Calculate revenue chart data
            $currentYear = Carbon::now()->year;
            $currentMonth = Carbon::now()->month;

            // Weekly (Last 7 days) revenue
            $startDate = Carbon::now()->subDays(6)->startOfDay();
            $weeklyRevenue = Transaction::where('status', 'paid')
                ->where('created_at', '>=', $startDate)
                ->selectRaw('DATE(created_at) as day, SUM(total) as revenue')
                ->groupBy('day')
                ->orderBy('day')
                ->get()
                ->keyBy('day');

            $weeklyChart = [
                'day_1' => '0.00',
                'day_2' => '0.00',
                'day_3' => '0.00',
                'day_4' => '0.00',
                'day_5' => '0.00',
                'day_6' => '0.00',
                'day_7' => '0.00',
            ];

            $days = [];
            for ($i = 6; $i >= 0; $i--) {
                $days[] = Carbon::now()->subDays($i)->toDateString();
            }

            foreach ($days as $index => $day) {
                $dayKey = 'day_' . ($index + 1);
                if (isset($weeklyRevenue[$day])) {
                    $weeklyChart[$dayKey] = number_format(($weeklyRevenue[$day]->revenue ?? 0), 2);
                }
            }

            // Monthly (Weekly) revenue
            $monthlyRevenue = Transaction::where('status', 'paid')
                ->whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $currentMonth)
                ->selectRaw('WEEK(created_at) - WEEK(DATE_SUB(DATE_FORMAT(NOW(), "%Y-%m-01"), INTERVAL 1 DAY)) + 1 as week, SUM(total) as revenue')
                ->groupBy('week')
                ->orderBy('week')
                ->get()
                ->keyBy('week');

            $monthlyChart = [
                'week_1' => '0.00',
                'week_2' => '0.00',
                'week_3' => '0.00',
                'week_4' => '0.00',
            ];

            for ($week = 1; $week <= 4; $week++) {
                if (isset($monthlyRevenue[$week])) {
                    $monthlyChart['week_' . $week] = number_format(($monthlyRevenue[$week]->revenue ?? 0), 2);
                }
            }

            // Yearly revenue
            $yearlyRevenue = Transaction::where('status', 'paid')
                ->whereYear('created_at', $currentYear)
                ->selectRaw('MONTH(created_at) as month, SUM(total) as revenue')
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->keyBy('month');

            $yearlyChart = [
                'january' => '0.00',
                'february' => '0.00',
                'march' => '0.00',
                'april' => '0.00',
                'may' => '0.00',
                'june' => '0.00',
                'july' => '0.00',
                'august' => '400.00',
                'september' => '0.00',
                'october' => '0.00',
                'november' => '0.00',
                'december' => '0.00',
            ];

            $months = [
                1 => 'january', 2 => 'february', 3 => 'march', 4 => 'april',
                5 => 'may', 6 => 'june', 7 => 'july', 8 => 'august',
                9 => 'september', 10 => 'october', 11 => 'november', 12 => 'december',
            ];

            foreach ($months as $monthNum => $monthName) {
                if (isset($yearlyRevenue[$monthNum])) {
                    $yearlyChart[$monthName] = number_format(($yearlyRevenue[$monthNum]->revenue ?? 0), 2);
                }
            }

            return response()->json([
                'message' => 'Dashboard statistics retrieved successfully',
                'data' => $stats,
                'chart' => [
                    'revenue' => [
                        'weekly' => $weeklyChart,
                        'monthly' => $monthlyChart,
                        'yearly' => $yearlyChart,
                    ]
                ]
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve dashboard stats:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to retrieve dashboard stats: ' . $e->getMessage()], 500);
        }
    }
}
