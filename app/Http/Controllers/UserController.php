<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Maindish;
use App\Models\Sidedish;
use App\Models\Dessert;
use App\Models\Statistics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * Display the place order page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function index()
    {
        // Fetch main dishes, side dishes, and desserts from the database
        $mainDishes = Maindish::all();
        $sideDishes = Sidedish::all();
        $desserts = Dessert::all();

        return view('home', compact('mainDishes', 'sideDishes', 'desserts'));
    }

    /**
     * Handle place order function.
     *
     * @return \Illuminate\View\View
     */
    public function store(Request $request)
    {
        try{
            // Validate the request
            $request->validate([
                'name' => 'required|string|max:255',
                'main_dish' => 'required|exists:main_dishes,id',
                'side_dish' => 'required|exists:side_dishes,id',
                'dessert' => 'nullable|exists:desserts,id',
            ]);

            // Calculate the total price
            $mainDish = Maindish::findOrFail($request->input('main_dish'));
            $sideDish = Sidedish::findOrFail($request->input('side_dish'));
            $dessert = $request->input('dessert') ? Dessert::findOrFail($request->input('dessert')) : null;

            $totalPrice = $mainDish->price + $sideDish->price + ($dessert ? $dessert->price : 0);

            // Create a new customer record
            Customer::create([
                'name' => $request->input('name'),
                'main_dish_id' => $mainDish->id,
                'side_dish_id' => $sideDish->id,
                'dessert_id' => $dessert ? $dessert->id : null,
                'total_price' => $totalPrice,
            ]);

            return redirect()->back()->with('success', 'Order placed successfully!');

        } catch (Exception $e) {
            // Place order failed
            $response = [
                'message' => "Place order failed. Please try again.",
                'error' => $e->getMessage(),
            ];
    
            return redirect()->back()->withErrors($response);
        }
        
    }


    /**
     * Display the order details page.
     *
     * @return \Illuminate\View\View
     */
    public function showOrders()
    {
        $orders = Customer::with(['maindish', 'sidedish', 'dessert'])->get();

        return view('orders', compact('orders'));
    }


    /**
     * Display the statistics page.
     *
     * @return \Illuminate\View\View
     */
    public function statistics()
    {
        $this->calculateStatistics();
        $statistics = Statistics::all();

        // Get the most famouse main dish
        $mostFamousMainDishFromAllRecords = Customer::select('main_dish_id', DB::raw('count(main_dish_id) as count'))
            ->groupBy('main_dish_id')
            ->orderByDesc('count')
            ->first();

        // Get the most famouse side dish
        $mostFamousSideDishFromAllRecords = Customer::select('side_dish_id', DB::raw('count(side_dish_id) as count'))
            ->groupBy('side_dish_id')
            ->orderByDesc('count')
            ->first();

        // Check if $mostFamousMainDishFromAllRecords is not null
        if ($mostFamousMainDishFromAllRecords) {
            // Select the side dish and the count of each side dish associated with the most consumed main dish
            $sideDishStatistics = Customer::select('side_dish_id', DB::raw('count(*) as count'))
                ->where('main_dish_id', $mostFamousMainDishFromAllRecords->main_dish_id)
                ->groupBy('side_dish_id')
                ->orderByDesc('count');

            // Get the first record for the most consumed side dish with the most consumed main dish
            $mostConsumedSideDishWithMostConsumedSideDish = DB::table(DB::raw("({$sideDishStatistics->toSql()}) as sub"))
                ->mergeBindings($sideDishStatistics->getQuery())
                ->select('side_dish_id', 'count')
                ->first();

                $mostFamousMainDishName = Maindish::find($mostFamousMainDishFromAllRecords->main_dish_id)->main_dish ?? 'N/A';
                $mostFamousSideDishName = Sidedish::find($mostFamousSideDishFromAllRecords->side_dish_id)->side_dish ?? 'N/A';
                $mostConsumedSideDishNameWithMostConsumedMainDish = Sidedish::find($mostConsumedSideDishWithMostConsumedSideDish->side_dish_id)->side_dish ?? 'N/A';
        } else {
            $mostFamousMainDishName=null;
            $mostFamousSideDishName=null;
            $mostConsumedSideDishWithMostConsumedSideDish = null;
            $mostConsumedSideDishNameWithMostConsumedMainDish=null;
        }

        return view('statistics', compact('statistics', 'mostFamousMainDishName','mostFamousSideDishName','mostConsumedSideDishNameWithMostConsumedMainDish'));
        
    }


    /**
     * Calculate daily sales revenue
     * Calculate most_famous_main_dish
     * Calculate most_famous_side_dish
     * Create table record for day
     *
     * @return \Illuminate\View\View
     */
    public function calculateStatistics()
    {
        $date = now()->toDateString();

        $existingRecord = Statistics::where('date', $date)->first();

        $dailySalesRevenue = Customer::whereDate('created_at', $date)->sum('total_price');

        // Get the most famouse main dish for particular date
        $mostFamousMainDish = Customer::whereDate('created_at', $date)
            ->select('main_dish_id', DB::raw('count(main_dish_id) as count'))
            ->groupBy('main_dish_id')
            ->orderByDesc('count')
            ->first();

        // Get the most famouse side dish for particular date
        $mostFamousSideDish = Customer::whereDate('created_at', $date)
            ->select('side_dish_id', DB::raw('count(side_dish_id) as count'))
            ->groupBy('side_dish_id')
            ->orderByDesc('count')
            ->first();

        if ($existingRecord) {
            // If record is available for particular date it will update
            $existingRecord->update([
                'daily_sales_revenue' => $dailySalesRevenue,
                'most_famous_main_dish_id' => $mostFamousMainDish->main_dish_id ?? null,
                'most_famous_side_dish_id' => $mostFamousSideDish->side_dish_id ?? null,
            ]);

            return redirect()->route('statistics.index');
        } else {

            // Save the record in statistic table
            Statistics::create([
                'date' => $date,
                'daily_sales_revenue' => $dailySalesRevenue,
                'most_famous_main_dish_id' => $mostFamousMainDish->main_dish_id ?? null,
                'most_famous_side_dish_id' => $mostFamousSideDish->side_dish_id ?? null,
            ]);

            return redirect()->route('statistics.index');
        }
    }


}
