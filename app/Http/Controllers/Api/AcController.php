<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Validator;
use JWTAuth;
use Carbon\Carbon;
use DB;
use App\User;
use App\ACOrder;

class AcController extends Controller
{
    function search_ac_vendor(Request $request)
    {
    	if($request->json('current_location')==1){
            $validator = Validator::make($request->all(), [
                'current_location'   => 'required',
                'lat'                => 'required',
                'lng'                => 'required',
                'address'            => 'required',
            ]);

    		$lat 	 	= $request->json('lat');
    		$long 	 	= $request->json('lng');
        	$address 	= $request->json('address');
        }
        else{
        	$validator = Validator::make($request->all(), [
                'current_location'   => 'required'
            ]);

    		$lat 		= $request->user()->latitude;
    		$long 	 	= $request->user()->longitude;
    		$address 	= $request->user()->address;
        }

        if ($validator->fails()) {
            return ApiResponse::response(['success'=>-1, 'message'=>$validator->errors()->getMessages()]);
        }

        $minDeposit = 5000;

        $notExceedThanLimit = DB::table('ac_order')
                                    ->select('ac_vendor_id', DB::raw('count(ac_vendor_id) as total'))
                                    ->groupBy('ac_vendor_id')
                                    ->havingRaw('total >= 3')
                                    ->where(function($query){
                                                $query->where('status', 0)
                                                    ->orWhere('status', 1);
                                            })
                                    ->pluck('ac_vendor_id');

        $data = User::query()
                    ->select('*', DB::raw('( 6371 * acos( cos( radians('.$lat.') ) * cos( radians( latitude ) ) * cos( radians( longitude ) - radians('.$long.') ) + sin( radians('.$lat.') ) * sin( radians( latitude ) ) ) ) AS distance'))        
                    ->where('status', 1)
                    ->where('role', 5)
                    ->where('deposit', '>=', $minDeposit)
                    ->whereNotIn('id', $notExceedThanLimit)
                    ->orderBy('distance', 'asc')
                    ->first();
        return ApiResponse::response([
        						'success'=>0, 
        						'data'=>[
        							'ac_vendor' => $notExceedThanLimit,
        							'your_order_data' =>[
        								'delivered_latitude'=>$lat,
        								'delivered_longitude'=>$long,
    									'delivered_address'=>$address
        							]
        						]
        					]);
    }

    function create_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ac_vendor_id'  	 => 'required',
            'address'            => 'required',
            'lat'                => 'required',
            'lng'                => 'required',
        ]);

        if ($validator->fails()) {
            return ApiResponse::response(['success'=>-1, 'message'=>$validator->errors()->getMessages()]);
        }

		$ac_vendor_id 	 	 = $request->json('ac_vendor_id');
		$lat 	 		 	 = $request->json('lat');
		$long 	 		 	 = $request->json('lng');
    	$address 		 	 = $request->json('address');
    	$order_description 	 = $request->json('order_description');
    	$order_time 		 = $request->json('order_time');
    	$order_date 		 = $request->json('order_date');

    	$order = new ACOrder;
    	$order->user_id 			= $request->user()->id;
    	$order->ac_vendor_id 		= $ac_vendor_id;
    	$order->delivered_address 	= $address;
    	$order->delivered_lat 		= $lat;
    	$order->delivered_lng 		= $long;
    	$order->order_description 	= $order_description;
    	$order->order_time 			= $order_time;
    	$order->order_date 			= $order_date;
    	$order->status 				= 0;
    	$order->save();

        return ApiResponse::response(['success'=>1, 'message'=>'order berhasil']);
    }

    public function order_list_user_view(Request $request)
    {
    	$validator = Validator::make($request->all(), [
            'limit'   => 'required',
            'offset'  => 'required',
        ]);

        if ($validator->fails()) {
            return ApiResponse::response(['success'=>-1, 'message'=>$validator->errors()->getMessages()]);
        }

        $data = DB::table('ac_order AS a')
    				->join('users AS b', 'a.ac_vendor_id', '=', 'b.id')
    				->select('a.id', 'a.delivered_address', 'a.created_at', 'a.status', 'a.order_description', 'a.order_date', 'a.order_time', 'b.fullname AS vendor_name')
    				->where('a.user_id', $request->user()->id)
    				->where(function($query){
					        	$query->where('a.status', 0)
					            	->orWhere('a.status', 1);
					    	})
    				->orderBy('a.created_at', 'desc')
                    ->skip($request->offset)
                    ->take($request->limit)
    				->get();

        return ApiResponse::response(['success'=>1, 'data'=>$data]);
    }

    public function order_list_vendor_view(Request $request)
    {
    	$validator = Validator::make($request->all(), [
            'limit'   => 'required',
            'offset'  => 'required',
        ]);

        if ($validator->fails()) {
            return ApiResponse::response(['success'=>-1, 'message'=>$validator->errors()->getMessages()]);
        }

        $data = DB::table('ac_order AS a')
    				->join('users AS b', 'a.user_id', '=', 'b.id')
    				->select('a.id', 'a.delivered_address', 'a.created_at', 'a.status', 'a.order_description', 'a.order_date', 'a.order_time', 'b.fullname AS user_name')
    				->where('a.ac_vendor_id', $request->user()->id)
    				->where(function($query){
					        	$query->where('a.status', 0)
					            	->orWhere('a.status', 1);
					    	})
    				->orderBy('a.created_at', 'desc')
                    ->skip($request->offset)
                    ->take($request->limit)
    				->get();

        return ApiResponse::response(['success'=>1, 'data'=>$data]);
    }

    public function ac_order_accept(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
        ]);

        if ($validator->fails()) {
            return ApiResponse::response(['success'=>-1, 'message'=>$validator->errors()->getMessages()]);
        }

        $id = $request->json('id');
        $order = ACOrder::find($id);
        $order->status = 1;
        $order->save();

        return ApiResponse::response(['success'=>1, 'message'=>'order berhasil diterima']);
    }

    public function ac_order_approve(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
        ]);

        if ($validator->fails()) {
            return ApiResponse::response(['success'=>-1, 'message'=>$validator->errors()->getMessages()]);
        }

        $id = $request->json('id');
        $order = ACOrder::find($id);
        $vendor_id = $order->ac_vendor_id;

        $user = User::find($vendor_id);
        $deposit = $user->deposit;

        $depositMin = 5000;

        $depositTotal = $deposit - $depositMin;
        
        if($depositMin > $deposit){
            $message = 'approve order gagal. saldo tidak cukup';
        }
        else{
            $user->deposit = $depositTotal;
            $user->save();

            $message = 'approve order berhasil';
            $order->status = 2;
            $order->save();
        }

        return ApiResponse::response(['success'=>1, 'message'=>$message]);
    }

    public function ac_order_cancel(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'reason_for_cancel' => 'required',
        ]);

        if ($validator->fails()) {
            return ApiResponse::response(['success'=>-1, 'message'=>$validator->errors()->getMessages()]);
        }

        $id = $request->json('id');
        $order = ACOrder::find($id);
        $order->status = -1;
        $order->reason_for_cancel = $request->json('reason_for_cancel');
        $order->save();

        return ApiResponse::response(['success'=>1, 'message'=>'order berhasil dicancel']);
    }

    public function order_log_vendor_view(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit'   => 'required',
            'offset'  => 'required',
        ]);

        if ($validator->fails()) {
            return ApiResponse::response(['success'=>-1, 'message'=>$validator->errors()->getMessages()]);
        }

        $data = DB::table('ac_order AS a')
    				->join('users AS b', 'a.user_id', '=', 'b.id')
    				->select('a.id', 'a.delivered_address', 'a.created_at', 'a.status', 'a.order_description', 'a.order_date', 'a.order_time', 'b.fullname AS user_name')
    				->where('a.ac_vendor_id', $request->user()->id)
    				->where(function($query){
					        	$query->where('a.status', 2)
					            	->orWhere('a.status', -1);
					    	})
    				->orderBy('a.created_at', 'desc')
                    ->skip($request->offset)
                    ->take($request->limit)
    				->get();

        return ApiResponse::response(['success'=>1, 'data'=>$data]);
    }

    public function order_log_user_view(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit'   => 'required',
            'offset'  => 'required',
        ]);

        if ($validator->fails()) {
            return ApiResponse::response(['success'=>-1, 'message'=>$validator->errors()->getMessages()]);
        }

        $data = DB::table('ac_order AS a')
    				->join('users AS b', 'a.ac_vendor_id', '=', 'b.id')
    				->select('a.id', 'a.delivered_address', 'a.created_at', 'a.status', 'a.order_description', 'a.order_date', 'a.order_time', 'b.fullname AS vendor_name')
    				->where('a.user_id', $request->user()->id)
    				->where(function($query){
					        	$query->where('a.status', 2)
					            	->orWhere('a.status', -1);
					    	})
    				->orderBy('a.created_at', 'desc')
                    ->skip($request->offset)
                    ->take($request->limit)
    				->get();

        return ApiResponse::response(['success'=>1, 'data'=>$data]);
    }
}
