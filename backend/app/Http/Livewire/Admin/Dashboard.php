<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;


class Dashboard extends Component
{
    public $stats = [];
    public $userTypeStats = [];
    public $recentActivity = [];

    public function mount()
    {
        $this->loadStats();
        //$this->loadUserTypeStats();
        $this->loadRecentActivity();
    }

    protected function loadStats()
    {
        $this->stats = [
            'total_users' => User::count(),
            'active_users' => User::where('status', 'active')->count(),
            'system_users' => User::systemUsers()->count(),
            'staff_users' => User::staffUsers()->count(),
            'customers' => User::customers()->count(),
            
            // These will work once you have the tables
            // 'total_orders' => Order::count(),
            // 'pending_orders' => Order::where('status', 'pending')->count(),
            // 'today_orders' => Order::whereDate('created_at', today())->count(),
            // 'total_products' => Product::count(),
            // 'low_stock_products' => Product::whereColumn('stock', '<=', 'low_stock_threshold')->count(),
            // 'today_sales' => Order::whereDate('created_at', today())
            //     ->where('status', 'completed')
            //     ->sum('total'),
        ];
    }

    protected function loadUserTypeStats()
    {
        $this->userTypeStats = User::select('user_type', DB::raw('count(*) as count'))
            ->groupBy('user_type')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->user_type => $item->count];
            })
            ->toArray();
    }

    protected function loadRecentActivity()
    {
        // Get recent users (last 10)
        $this->recentActivity = User::latest()
            ->take(10)
            ->get()
            ->map(function ($user) {
                return [
                    'type' => 'user_created',
                    'description' => "New {$user->getUserTypeLabel()} registered",
                    'user' => $user->name,
                    'time' => $user->created_at->diffForHumans(),
                ];
            });
    }

    public function render()
    {
        return view('livewire.admin.dashboard')
            ->layout('layouts.admin', ['title' => 'Dashboard']);
    }
}