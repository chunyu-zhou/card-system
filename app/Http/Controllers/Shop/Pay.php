<?php
namespace App\Http\Controllers\Shop; use App\Card; use App\Category; use App\Library\FundHelper; use App\Library\Helper; use App\Library\WeChatMessage; use App\Product; use App\Order; use App\Library\Response; use App\Library\Pay\Pay as PayApi; use App\Library\Geetest; use App\Mail\OrderShipped; use App\Mail\ProductCountWarn; use App\System; use Carbon\Carbon; use Illuminate\Database\Eloquent\Relations\Relation; use Illuminate\Http\Request; use App\Http\Controllers\Controller; use Illuminate\Support\Facades\Cookie; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Log; use Illuminate\Support\Facades\Mail; class Pay extends Controller { public function __construct() { define('SYS_NAME', config('app.name')); define('SYS_URL', config('app.url')); define('SYS_URL_API', config('app.url_api')); } private $payApi = null; public function goPay($sp0fc69c, $sp044e8f, $spbaf760, $spfc2b4a, $sp1a555d) { try { (new PayApi())->goPay($spfc2b4a, $sp044e8f, $spbaf760, $spbaf760, $sp1a555d); return self::renderResult($sp0fc69c, array('success' => false, 'title' => '请稍后', 'msg' => '支付方式加载中，请稍后')); } catch (\Exception $sp2a4a9a) { return self::renderResult($sp0fc69c, array('msg' => $sp2a4a9a->getMessage())); } } function buy(Request $sp0fc69c) { if ((int) System::_get('vcode_shop_buy') === 1) { $spc8aebe = Geetest\API::verify($sp0fc69c->input('geetest_challenge'), $sp0fc69c->input('geetest_validate'), $sp0fc69c->input('geetest_seccode')); if (!$spc8aebe) { self::renderResult($sp0fc69c, array('msg' => '系统无法接受您的验证结果，请刷新页面后重试。')); } } $spd1c19c = Cookie::get('customer'); if (strlen($spd1c19c) !== 32) { return self::renderResult($sp0fc69c, array('msg' => '请返回页面重新下单')); } $sp3c5594 = (int) $sp0fc69c->input('category_id'); $spedd229 = (int) $sp0fc69c->input('product_id'); $sp1f1cf0 = (int) $sp0fc69c->input('count'); $sp08578a = $sp0fc69c->input('coupon'); $spbe38e2 = $sp0fc69c->input('email'); $sp8b61af = (int) $sp0fc69c->input('pay_id'); if (!$sp3c5594 || !$spedd229) { return self::renderResult($sp0fc69c, array('msg' => '请选择商品')); } if (strlen($spbe38e2) < 1) { return self::renderResult($sp0fc69c, array('msg' => '请输入邮箱')); } $sp67f4a3 = Category::findOrFail($sp3c5594); if ($sp67f4a3->password_open) { if ($sp67f4a3->password !== $sp0fc69c->post('category_password')) { return Response::fail('分类密码输入错误'); } } $spcf7f28 = Product::where('id', $spedd229)->where('category_id', $sp3c5594)->where('enabled', 1)->with(array('cards' => function (Relation $sp3f78ce) { $sp3f78ce->whereRaw('`count_all`>`count_sold`')->selectRaw('`product_id`,SUM(`count_all`-`count_sold`) as `count`')->groupBy('product_id'); }))->first(); if ($spcf7f28 == null || $spcf7f28->user == null) { return self::renderResult($sp0fc69c, array('msg' => '该商品未找到，请重新选择')); } if ($spcf7f28->password_open) { if ($spcf7f28->password !== $sp0fc69c->post('product_password')) { return Response::fail('分类密码输入错误'); } } if ($sp1f1cf0 < $spcf7f28->buy_min) { return self::renderResult($sp0fc69c, array('msg' => '该商品最少购买' . $spcf7f28->buy_min . '件，请重新选择')); } if ($sp1f1cf0 > $spcf7f28->buy_max) { return self::renderResult($sp0fc69c, array('msg' => '该商品限购' . $spcf7f28->buy_max . '件，请重新选择')); } $spcf7f28->setAttribute('count', count($spcf7f28->cards) ? $spcf7f28->cards[0]->count : 0); if ($spcf7f28->count < $sp1f1cf0) { return self::renderResult($sp0fc69c, array('msg' => '该商品库存不足')); } $sp8444d3 = \App\Pay::find($sp8b61af); if ($sp8444d3 == null || !$sp8444d3->enabled) { return self::renderResult($sp0fc69c, array('msg' => '支付方式未找到，请重新选择')); } $spdbf2c8 = $spcf7f28->price; if ($spcf7f28->price_whole) { $spf00466 = json_decode($spcf7f28->price_whole, true); for ($sp1b7341 = count($spf00466) - 1; $sp1b7341 >= 0; $sp1b7341--) { if ($sp1f1cf0 >= (int) $spf00466[$sp1b7341][0]) { $spdbf2c8 = (int) $spf00466[$sp1b7341][1]; break; } } } $spf6f2b2 = $sp1f1cf0 * $spdbf2c8; $sp1a555d = $spf6f2b2; $sp2f2b5a = null; if ($spcf7f28->support_coupon && strlen($sp08578a) > 0) { $sp144bbf = \App\Coupon::where('user_id', $spcf7f28->user_id)->where('coupon', $sp08578a)->where('expire_at', '>', Carbon::now())->whereRaw('`count_used`<`count_all`')->get(); foreach ($sp144bbf as $sp356855) { if ($sp356855->category_id === -1 || $sp356855->category_id === $sp3c5594 && ($sp356855->product_id === -1 || $sp356855->product_id === $spedd229)) { if ($sp356855->discount_type === \App\Coupon::DISCOUNT_TYPE_AMOUNT && $sp1a555d > $sp356855->discount_val) { $sp2f2b5a = $sp356855; $sp1a555d = $sp1a555d - $sp356855->discount_val; break; } if ($sp356855->discount_type === \App\Coupon::DISCOUNT_TYPE_PERCENT) { $sp2f2b5a = $sp356855; $sp1a555d = $sp1a555d - intval($sp1a555d * $sp356855->discount_val / 100); break; } } } } if ($sp2f2b5a) { $sp2f2b5a->status = \App\Coupon::STATUS_USED; $sp2f2b5a->count_used++; $sp2f2b5a->save(); } $spe02c4d = (int) round($sp1a555d * $sp8444d3->fee_system); $spf0b327 = $sp1a555d - $spe02c4d; $sp044e8f = date('YmdHis') . str_random(5); while (Order::whereOrderNo($sp044e8f)->exists()) { $sp044e8f = date('YmdHis') . str_random(5); } Order::insert(array('user_id' => $spcf7f28->user_id, 'order_no' => $sp044e8f, 'product_id' => $spedd229, 'count' => $sp1f1cf0, 'email' => $spbe38e2, 'ip' => Helper::getIP(), 'customer' => $spd1c19c, 'email_sent' => false, 'cost' => $sp1f1cf0 * $spcf7f28->cost, 'price' => $spf6f2b2, 'discount' => $spf6f2b2 - $sp1a555d, 'paid' => $sp1a555d, 'pay_id' => $sp8444d3->id, 'fee' => $spe02c4d, 'system_fee' => $spe02c4d, 'income' => $spf0b327, 'status' => Order::STATUS_UNPAY, 'created_at' => Carbon::now())); return Response::success(array('order_no' => $sp044e8f)); } function pay(Request $sp0fc69c, $sp044e8f) { $sp7fd294 = Order::whereOrderNo($sp044e8f)->first(); if ($sp7fd294 == null) { return self::renderResult($sp0fc69c, array('msg' => '订单未找到，请重试')); } if ($sp7fd294->status !== \App\Order::STATUS_UNPAY) { return redirect('/pay/result/' . $sp044e8f); } $spe770c0 = 'pay: ' . $sp7fd294->pay_id; $spfc2b4a = $sp7fd294->pay; if (!$spfc2b4a) { Log::error($spe770c0 . ' cannot find Pay'); return $this->renderResult($sp0fc69c, array('msg' => '支付方式未找到')); } $spe770c0 .= ',' . $spfc2b4a->driver; $sp8abf69 = json_decode($spfc2b4a->config, true); $sp8abf69['payway'] = $spfc2b4a->way; $sp8abf69['out_trade_no'] = $sp044e8f; try { $this->payApi = PayApi::getDriver($spfc2b4a->id, $spfc2b4a->driver); } catch (\Exception $sp2a4a9a) { Log::error($spe770c0 . ' cannot find Driver: ' . $sp2a4a9a->getMessage()); return $this->renderResult($sp0fc69c, array('msg' => '支付驱动未找到')); } if ($this->payApi->verify($sp8abf69, function ($sp044e8f, $spbd56f5, $sp2296f4) use($sp0fc69c) { try { $this->shipOrder($sp0fc69c, $sp044e8f, $spbd56f5, $sp2296f4, FALSE); } catch (\Exception $sp2a4a9a) { $this->renderResult($sp0fc69c, array('success' => false, 'msg' => $sp2a4a9a->getMessage())); } })) { Log::notice($spe770c0 . ' already success' . '

'); return redirect('/pay/result/' . $sp044e8f); } $spcf7f28 = Product::where('id', $sp7fd294->product_id)->where('enabled', 1)->with(array('cards' => function (Relation $sp3f78ce) { $sp3f78ce->whereRaw('`count_all`>`count_sold`')->selectRaw('`product_id`,SUM(`count_all`-`count_sold`) as `count`')->groupBy('product_id'); }))->first(); if ($spcf7f28 == null) { return self::renderResult($sp0fc69c, array('msg' => '该商品已下架')); } $spcf7f28->setAttribute('count', count($spcf7f28->cards) ? $spcf7f28->cards[0]->count : 0); if ($spcf7f28->count < $sp7fd294->count) { return self::renderResult($sp0fc69c, array('msg' => '该商品库存不足')); } $spbaf760 = $sp044e8f; return $this->goPay($sp0fc69c, $sp044e8f, $spbaf760, $spfc2b4a, $sp7fd294->paid); } function qrcode(Request $sp0fc69c, $sp044e8f, $spe5e439) { $sp7fd294 = Order::whereOrderNo($sp044e8f)->with('product')->first(); if ($sp7fd294 == null) { return self::renderResult($sp0fc69c, array('msg' => '订单未找到，请重试')); } if ($sp7fd294->product_id !== \App\Product::ID_API && $sp7fd294->product == null) { return self::renderResult($sp0fc69c, array('msg' => '商品未找到，请重试')); } $sp20dce2 = $sp0fc69c->get('url'); return view('pay/' . $spe5e439, array('pay_id' => $sp7fd294->pay_id, 'name' => $sp7fd294->product_id === \App\Product::ID_API ? $sp7fd294->api_out_no : $sp7fd294->product->name, 'qrcode' => $sp20dce2, 'id' => $sp044e8f)); } function qrQuery(Request $sp0fc69c, $sp8b61af) { $spb6f952 = $sp0fc69c->input('id', ''); return self::payReturn($sp0fc69c, $sp8b61af, $spb6f952); } function payReturn(Request $sp0fc69c, $sp8b61af, $spbd054b = '') { $spe770c0 = 'payReturn: ' . $sp8b61af; \Log::debug($spe770c0); $spfc2b4a = \App\Pay::where('id', $sp8b61af)->first(); if (!$spfc2b4a) { return $this->renderResult($sp0fc69c, array('success' => 0, 'msg' => '支付方式错误')); } $spe770c0 .= ',' . $spfc2b4a->driver; if (strlen($spbd054b) > 0) { $sp7fd294 = Order::whereOrderNo($spbd054b)->first(); if ($sp7fd294 && ($sp7fd294->status === Order::STATUS_PAID || $sp7fd294->status === Order::STATUS_SUCCESS)) { \Log::notice($spe770c0 . ' already success' . '

'); if ($sp0fc69c->ajax()) { return self::renderResult($sp0fc69c, array('success' => 1, 'data' => '/pay/result/' . $spbd054b), array('order' => $sp7fd294)); } else { return redirect('/pay/result/' . $spbd054b); } } } try { $this->payApi = PayApi::getDriver($spfc2b4a->id, $spfc2b4a->driver); } catch (\Exception $sp2a4a9a) { \Log::error($spe770c0 . ' cannot find Driver: ' . $sp2a4a9a->getMessage()); return $this->renderResult($sp0fc69c, array('success' => 0, 'msg' => '支付驱动未找到')); } $sp8abf69 = json_decode($spfc2b4a->config, true); $sp8abf69['out_trade_no'] = $spbd054b; $sp8abf69['payway'] = $spfc2b4a->way; \Log::debug($spe770c0 . ' will verify'); if ($this->payApi->verify($sp8abf69, function ($sp044e8f, $spbd56f5, $sp2296f4) use($sp0fc69c, $spe770c0, &$spbd054b) { $spbd054b = $sp044e8f; try { \Log::debug($spe770c0 . " shipOrder start, order_no: {$sp044e8f}, amount: {$spbd56f5}, trade_no: {$sp2296f4}"); $this->shipOrder($sp0fc69c, $sp044e8f, $spbd56f5, $sp2296f4, FALSE); \Log::debug($spe770c0 . ' shipOrder end, order_no: ' . $sp044e8f); } catch (\Exception $sp2a4a9a) { \Log::error($spe770c0 . ' shipOrder Exception: ' . $sp2a4a9a->getMessage()); } })) { \Log::debug($spe770c0 . ' verify finished: 1' . '

'); if ($sp0fc69c->ajax()) { return self::renderResult($sp0fc69c, array('success' => 1, 'data' => '/pay/result/' . $spbd054b)); } else { return redirect('/pay/result/' . $spbd054b); } } else { \Log::debug($spe770c0 . ' verify finished: 0' . '

'); return $this->renderResult($sp0fc69c, array('success' => 0, 'msg' => '支付验证失败，您可以稍后查看支付状态。')); } } function payNotify(Request $sp0fc69c, $sp8b61af) { $spe770c0 = 'payNotify pay_id: ' . $sp8b61af; \Log::debug($spe770c0); $spfc2b4a = \App\Pay::where('id', $sp8b61af)->first(); if (!$spfc2b4a) { \Log::error($spe770c0 . ' cannot find PayModel'); echo 'fail'; die; } $spe770c0 .= ',' . $spfc2b4a->driver; try { $this->payApi = PayApi::getDriver($spfc2b4a->id, $spfc2b4a->driver); } catch (\Exception $sp2a4a9a) { \Log::error($spe770c0 . ' cannot find Driver: ' . $sp2a4a9a->getMessage()); echo 'fail'; die; } $sp8abf69 = json_decode($spfc2b4a->config, true); $sp8abf69['payway'] = $spfc2b4a->way; $sp8abf69['isNotify'] = true; \Log::debug($spe770c0 . ' will verify'); $spc8aebe = $this->payApi->verify($sp8abf69, function ($sp044e8f, $spbd56f5, $sp2296f4) use($sp0fc69c, $spe770c0) { try { \Log::debug($spe770c0 . " shipOrder start, order_no: {$sp044e8f}, amount: {$spbd56f5}, trade_no: {$sp2296f4}"); $this->shipOrder($sp0fc69c, $sp044e8f, $spbd56f5, $sp2296f4, FALSE); \Log::debug($spe770c0 . ' shipOrder end, order_no: ' . $sp044e8f); } catch (\Exception $sp2a4a9a) { \Log::error($spe770c0 . ' shipOrder Exception: ' . $sp2a4a9a->getMessage()); } }); \Log::debug($spe770c0 . ' notify finished: ' . (int) $spc8aebe . '

'); die; } function result(Request $sp0fc69c, $sp044e8f) { $sp7fd294 = Order::whereOrderNo($sp044e8f)->first(); if ($sp7fd294 == null) { return self::renderResult($sp0fc69c, array('msg' => '订单未找到，请重试')); } if ($sp7fd294->status === Order::STATUS_PAID) { $sp3dc1e9 = $sp7fd294->user->qq; $spbfa8f4 = '商家库存不足，因此卡密没有自动发货，请联系商家客服发货'; if ($sp3dc1e9) { $spbfa8f4 .= '<br><a href="http://wpa.qq.com/msgrd?v=3&uin=' . $sp3dc1e9 . '&site=qq&menu=yes" target="_blank">商家客服QQ:' . $sp3dc1e9 . '</a>'; } return self::renderResult($sp0fc69c, array('success' => false, 'title' => '订单已支付', 'msg' => $spbfa8f4), array('order' => $sp7fd294)); } elseif ($sp7fd294->status === Order::STATUS_SUCCESS) { return $this->shipOrder($sp0fc69c, $sp7fd294->order_no, $sp7fd294->paid, 0, TRUE); } return self::renderResult($sp0fc69c, array('success' => false, 'msg' => $sp7fd294->remark ? '失败原因:<br>' . $sp7fd294->remark : '订单支付失败，请重试'), array('order' => $sp7fd294)); } function renderResult(Request $sp0fc69c, $spbcb528, $spaa1fa7 = array()) { if ($sp0fc69c->ajax()) { if (@$spbcb528['success']) { return Response::success($spbcb528['data']); } else { return Response::fail('error', $spbcb528['msg']); } } else { return view('pay.result', array_merge(array('result' => $spbcb528, 'data' => $spaa1fa7), $spaa1fa7)); } } function shipOrder($sp0fc69c, $sp044e8f, $spbd56f5, $sp2296f4, $sp81510a = true) { $sp7fd294 = Order::whereOrderNo($sp044e8f)->first(); if ($sp7fd294 === null) { \Log::error('shipOrder: No query results for model [App\\Order:' . $sp044e8f . ',trade_no:' . $sp2296f4 . ',amount:' . $spbd56f5 . ']. die(\'success\');'); die('success'); } if ($sp7fd294->paid > $spbd56f5) { \Log::alert('shipOrder, price may error, order_no:' . $sp044e8f . ', paid:' . $sp7fd294->paid . ', $amount get:' . $spbd56f5); $sp7fd294->remark = '支付金额(' . sprintf('%0.2f', $spbd56f5 / 100) . ') 小于 订单金额(' . sprintf('%0.2f', $sp7fd294->paid / 100) . ')'; $sp7fd294->save(); throw new \Exception($sp7fd294->remark); } if ($sp7fd294->product_id === \App\Product::ID_API) { return (new ApiPay())->shipOrder($sp0fc69c, $sp7fd294, $sp2296f4, $sp81510a); } $spdaad30 = array(); $sp8db0b3 = ''; $sp406240 = ''; $spcf7f28 = null; $sp475edc = $sp7fd294->status === Order::STATUS_UNPAY; $spe2417a = $sp475edc && System::_getInt('mail_send_order') === 1 && filter_var($sp7fd294->email, FILTER_VALIDATE_EMAIL); if ($sp475edc) { \Log::debug('shipOrder.first_process:' . $sp044e8f); $speba899 = $sp7fd294->id; if (FundHelper::orderSuccess($sp7fd294, function () use($speba899, $sp2296f4, &$spdaad30, &$sp406240) { $sp7fd294 = Order::where('id', $speba899)->lockForUpdate()->firstOrFail(); if ($sp7fd294->status !== Order::STATUS_UNPAY) { \Log::debug('shipOrder.first_process:' . $sp7fd294->order_no . ' already processed!'); return -999; } $spcf7f28 = $sp7fd294->product()->lockForUpdate()->firstOrFail(); $spcf7f28->count_sold += $sp7fd294->count; $spcf7f28->saveOrFail(); $sp7fd294->pay_trade_no = $sp2296f4; $sp7fd294->paid_at = Carbon::now(); $spdaad30 = Card::where('product_id', $sp7fd294->product_id)->whereRaw('`count_sold`<`count_all`')->take($sp7fd294->count)->lockForUpdate()->get(); if (count($spdaad30) !== $sp7fd294->count) { \Log::alert('订单:' . $sp7fd294->order_no . ', 购买数量:' . $sp7fd294->count . ', 卡数量:' . count($spdaad30) . ' 卡密不足(已支付 未发货)'); $sp7fd294->status = Order::STATUS_PAID; $sp7fd294->saveOrFail(); return Order::STATUS_PAID; } else { $sp7fd294->status = Order::STATUS_SUCCESS; $sp7fd294->saveOrFail(); $spb08013 = array(); foreach ($spdaad30 as $sp0c5ad3) { $sp406240 .= $sp0c5ad3->card . '<br>'; $spb08013[] = $sp0c5ad3->id; } $sp7fd294->cards()->attach($spb08013); Card::whereIn('id', $spb08013)->update(array('status' => Card::STATUS_SOLD, 'count_sold' => DB::raw('`count_sold`+1'))); return Order::STATUS_SUCCESS; } })) { $spcf7f28 = Product::where('id', $sp7fd294->product_id)->with(array('cards' => function (Relation $sp3f78ce) { $sp3f78ce->whereRaw('`count_all`>`count_sold`')->selectRaw('`product_id`,SUM(`count_all`-`count_sold`) as `count`')->groupBy('product_id'); }))->first(); if ($spcf7f28) { $sp1f1cf0 = count($spcf7f28->cards) ? $spcf7f28->cards[0]->count : 0; $spcf7f28->setAttribute('count', $sp1f1cf0); if ($spcf7f28->count_warn > 0 && $sp1f1cf0 < $spcf7f28->count_warn) { try { \Mail::to($sp7fd294->user->email)->Queue(new ProductCountWarn($spcf7f28, $sp1f1cf0)); } catch (\Exception $sp2a4a9a) { \App\Library\LogHelper::setLogFile('mail'); \Log::error('shipOrder.count_warn error, product_id:' . $sp7fd294->product_id . ', email:' . $sp7fd294->user->email . ', Exception:' . $sp2a4a9a); \App\Library\LogHelper::setLogFile('card'); } if ($sp7fd294->user->wechat_is_notify('low_stocks')) { (new WeChatMessage())->low_stocks($sp7fd294->user, $spcf7f28); } } if ($sp7fd294->user->wechat_is_notify('order_success')) { (new WeChatMessage())->order_success($sp7fd294->user, $spcf7f28, $sp7fd294); } } } else { \Log::error('shipOrder.first_process error, order_no:' . $sp044e8f . ',trade_no:' . $sp2296f4); throw new \Exception('merchant operate exception!'); } } elseif ($sp81510a) { $sp8db0b3 = '订单已支付，卡号列表：'; $spdaad30 = $sp7fd294->cards; $spcf7f28 = $sp7fd294->product; foreach ($spdaad30 as $sp0c5ad3) { $sp406240 .= $sp0c5ad3->card . '
'; } } if ($sp81510a || $spe2417a) { if (count($spdaad30) < $sp7fd294->count) { if (count($spdaad30)) { $sp8db0b3 = '目前库存不足，您还有' . ($sp7fd294->count - count($spdaad30)) . '张卡密未发货，请联系商家客服发货<br>已发货卡密见下方：<br>'; } else { $sp8db0b3 = '目前库存不足，您购买的' . ($sp7fd294->count - count($spdaad30)) . '张卡密未发货，请联系商家客服发货<br>'; } $sp3dc1e9 = $sp7fd294->user->qq; if ($sp3dc1e9) { $sp8db0b3 .= '<a href="http://wpa.qq.com/msgrd?v=3&uin=' . $sp3dc1e9 . '&site=qq&menu=yes" target="_blank">商家客服QQ:' . $sp3dc1e9 . '</a><br>'; } } } if ($spe2417a) { $sp3bad3c = str_replace('
', '<br>', $sp406240); try { \Mail::to($sp7fd294->email)->Queue(new OrderShipped($sp7fd294, $sp3bad3c)); $sp7fd294->email_sent = true; $sp7fd294->saveOrFail(); } catch (\Exception $sp2a4a9a) { \App\Library\LogHelper::setLogFile('mail'); \Log::error('shipOrder.need_mail error, order_no:' . $sp044e8f . ', email:' . $sp7fd294->email . ', cards:' . $sp3bad3c . ', Exception:' . $sp2a4a9a->getMessage()); \App\Library\LogHelper::setLogFile('card'); } } if ($sp81510a) { return self::renderResult($sp0fc69c, array('success' => true, 'msg' => $sp8db0b3), array('card_txt' => $sp406240, 'order' => $sp7fd294, 'product' => $spcf7f28)); } return FALSE; } }