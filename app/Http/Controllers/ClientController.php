<?php

namespace App\Http\Controllers;

use App\Models\AttributeValue;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Variant;
use App\Models\Attribute;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ClientController extends Controller
{
    private Brand $brand;

    private Category $category;

    private Product $product;

    private Variant $variant;

    private Attribute $attribute;

    private AttributeValue $attributeValue;
    public function __construct()
    {
        $this->brand = new Brand();
        $this->category = new Category();
        $this->product = new Product();
        $this->variant = new Variant();
        $this->attribute = new Attribute();
        $this->attributeValue = new AttributeValue();
    }
    public function home()
    {
        $brands = Brand::all();
        $categories = Category::all();
        $products = Product::all();
        $banners = Banner::active()->main()->ordered()->get();
        $secondaryBanners = Banner::active()->secondary()->ordered()->limit(1)->get();
        return view('clients.layouts.home', compact('brands', 'categories', 'products', 'banners', 'secondaryBanners'));
    }

    public function index(Request $request)
    {
        $brands = Brand::all();
        $categories = Category::all();
        $attributeValues = AttributeValue::all();

        $query = Product::query();

        // ✅ Nếu có category đơn lẻ (từ menu), convert thành mảng categories[]
        if ($request->filled('category')) {
            $request->merge([
                'categories' => [$request->category]
            ]);
        }

        // ✅ Nếu có brand đơn lẻ (từ menu), convert thành mảng brands[]
        if ($request->filled('brand')) {
            $request->merge([
                'brands' => [$request->brand]
            ]);
        }

        // Tìm kiếm theo tên sản phẩm
        if ($request->filled('keyword')) {
            $query->where('name', 'like', '%' . $request->keyword . '%');
        }

        // ✅ Lọc theo nhiều danh mục (cả cha và con)
        if ($request->has('categories') && is_array($request->categories)) {
            $allCategoryIds = [];

            foreach ($request->categories as $catId) {
                $category = Category::find($catId);
                if ($category) {
                    if (is_null($category->parent_category_id)) {
                        $childIds = Category::where('parent_category_id', $category->id)->pluck('id')->toArray();
                        $allCategoryIds = array_merge($allCategoryIds, [$category->id], $childIds);
                    } else {
                        $allCategoryIds[] = $category->id;
                    }
                }
            }

            if (!empty($allCategoryIds)) {
                $query->whereIn('category_id', $allCategoryIds);
            }
        }

        // ✅ Lọc theo nhiều thương hiệu
        if ($request->has('brands') && is_array($request->brands)) {
            $query->whereIn('brand_id', $request->brands);
        }

        if ($request->has('attribute_value_ids') && is_array($request->attribute_value_ids)) {
            $query->whereHas('variants.attributeValues', function ($q) use ($request) {
                $q->whereIn('attributes_values.id', $request->attribute_value_ids); // 👈 bảng đúng
            });
        }


        // Lọc theo khoảng giá
        if ($request->filled('min_price')) {
            $query->where('base_price', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('base_price', '<=', $request->max_price);
        }

        // Phân trang sản phẩm kèm ảnh đại diện
        $products = $query->with('featuredImage')->paginate(9);

        // Trả về view
        return view('clients.products.productlist', compact(
            'brands',
            'categories',
            'products',
            'attributeValues'
        ));
    }

    public function showProduct($id)
    {
        $product = Product::with([
            'images',
            'featuredImage',
            'variants.attributeValues.attribute'
        ])->withTrashed()->find($id);
        $categories = Category::all();
        $brands = Brand::all();
        $error = null;
        $attributeValues = collect();
        if (!$product || $product->trashed()) {
            $error = 'Sản phẩm này không tồn tại hoặc đã bị xóa.';
        } else {
            $attributeValues = $product->variants
                ->flatMap(function ($variant) {
                    return $variant->attributeValues->map(function ($value) use ($variant) {
                        return [
                            'id' => $value->id,
                            'value' => $value->value,
                            'attribute_name' => $value->attribute->name ?? '',
                            'variant_id' => $variant->id,
                            'price' => $variant->price,
                        ];
                    });
                })->unique('id')->values();
        }
        return view('clients.products.productdetail', compact(
            'product',
            'categories',
            'brands',
            'attributeValues',
            'error'
        ));
    }

    public function showAllBrand()
    {
        $brands = Brand::all();
        $categories = Category::all();

        return view('clients.brands.brandlist', compact('brands', 'categories'));
    }

    public function viewCart()
    {
        $brands = Brand::all();
        $categories = Category::all();
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Bạn phải đăng nhập mới được vào giỏ hàng');
        }
        $cart = Cart::with(['cartItem.variant.product.images'])->where('user_id', $user->id)->first();

        return view('clients.carts.cartlist', compact('brands', 'categories', 'cart'));
    }

    public function addToCart(Request $request)
    {
        $request->validate([
            'variant_id' => 'required|exists:variants,id',
            'quantity'   => 'required|integer|min:1'
        ]);

        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Bạn phải đăng nhập mới thêm được vào giỏ hàng');
        }

        $variant = Variant::with('product')->findOrFail($request->variant_id);
        $product = $variant->product;
        // Kiểm tra sản phẩm đã bị xóa (soft delete) chưa
        if (!$product || $product->trashed()) {
            return redirect()->back()->with('error', 'Sản phẩm này đã bị xóa, không thể thêm vào giỏ hàng.');
        }
        // Kiểm tra tên sản phẩm truyền lên có khớp với tên hiện tại không
        if ($request->has('product_name') && $request->product_name !== $product->name) {
            return redirect()->back()->with('error', 'Sản phẩm vừa được cập nhật tên, vui lòng kiểm tra lại.');
        }

        $cart = Cart::firstOrCreate(
            ['user_id' => $user->id],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $cartItem = CartItem::where('cart_id', $cart->id)
            ->where('variant_id', $variant->id)
            ->first();

        $currentQuantityInCart = $cartItem ? $cartItem->quantity : 0;
        $newTotalQuantity = $currentQuantityInCart + $request->quantity;

        if ($newTotalQuantity > $variant->stock_quantity) {
            return redirect()->back()->with('error', 'Số lượng sản phẩm vượt quá tồn kho. Chỉ còn ' . $variant->stock_quantity . ' sản phẩm.');
        }

        if ($cartItem) {
            $cartItem->quantity = $newTotalQuantity;
            $cartItem->save();
        } else {
            CartItem::create([
                'cart_id'    => $cart->id,
                'variant_id' => $variant->id,
                'quantity'   => $request->quantity,
            ]);
        }

        $cart->load('cartItem.variant.product');
        $this->updateCartTotals($cart);

        return redirect()->back()->with('success', 'Đã thêm vào giỏ hàng!');
    }
    public function recalculate()
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['error' => 'Chưa đăng nhập'], 401);
        }

        $cart = $user->cart()->with(['cartItem.variant.product'])->first();

        if (!$cart || !$cart->cartItem) {
            return response()->json([
                'items' => [],
                'subtotal' => '0₫',
                'shipping' => '0₫',
                'discount' => '0₫',
                'total' => '0₫',
            ]);
        }

        $items = [];
        $subtotal = 0;

        foreach ($cart->cartItem as $item) {
            if (!$item->variant || !$item->variant->product) continue;

            $basePrice = $item->variant->product->base_price ?? 0;
            $variantPrice = $item->variant->price ?? 0;
            $unitPrice = $basePrice + $variantPrice;
            $lineTotal = $unitPrice * $item->quantity;

            $subtotal += $lineTotal;

            $items[] = [
                'id' => $item->id,
                'unit_price' => number_format($unitPrice, 0, ',', '.') . '₫',
                'line_total' => number_format($lineTotal, 0, ',', '.') . '₫',
            ];
        }

        // Giảm giá
        $coupon = session('applied_coupon', []);
        $discount = isset($coupon['discount']) ? (int)$coupon['discount'] : 0;

        $shipping = 50000;
        $total = $subtotal + $shipping - $discount;

        return response()->json([
            'items' => $items,
            'subtotal' => number_format($subtotal, 0, ',', '.') . '₫',
            'shipping' => number_format($shipping, 0, ',', '.') . '₫',
            'discount' => number_format($discount, 0, ',', '.') . '₫',
            'total' => number_format($total, 0, ',', '.') . '₫',
        ]);
    }
    public function updateQuantity(Request $request, $itemId)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $item = CartItem::with('variant.product')->findOrFail($itemId);
        $item->quantity = $request->quantity;
        $item->save();

        $cart = $item->cart->load('cartItem.variant.product');
        $this->updateCartTotals($cart);

        // ✅ Tính lại giá: base + variant
        $product = $item->variant->product;
        $basePrice = floatval($product->base_price);
        $variantPrice = floatval($item->variant->price ?? 0);
        $price = $basePrice + $variantPrice;
        $total = $price * $item->quantity;
        $subtotal = floatval($cart->total_price ?? 0);

        return response()->json([
            'success' => true,
            'total' => $total,
            'subtotal' => $subtotal,
        ]);
    }
    private function updateCartTotals(Cart $cart)
    {
        $totalQuantity = 0;
        $totalPrice = 0;

        foreach ($cart->cartItem as $item) {
            $product = $item->variant->product;
            $variantPrice = $item->variant->price ?? 0;

            $price = $product->base_price + $variantPrice;

            $totalQuantity += $item->quantity;
            $totalPrice += $price * $item->quantity;
        }

        $cart->update([
            'total_quantity' => $totalQuantity,
            'total_price' => $totalPrice,
        ]);
    }
    public function deleteProduct($itemId)
    {
        $item = CartItem::findOrFail($itemId);

        if (auth()->id() !== $item->cart->user_id) {
            return redirect()->back()->with('error', 'Bạn không có quyền xoá sản phẩm này.');
        }

        $cart = $item->cart;
        $item->delete();

        // ✅ load lại cart sau khi xoá để cập nhật
        $cart->load('cartItem.variant.product');
        $this->updateCartTotals($cart);

        return redirect()->back()->with('success', 'Sản phẩm đã được xoá khỏi giỏ hàng.');
    }

    public function viewCheckOut()
    {
        try {
            $brands = Brand::all();
            $categories = Category::all();
            $user = Auth::user();

            if (!$user) {
                return redirect()->route('login')->with('error', 'Vui lòng đăng nhập để thanh toán');
            }

            $cart = Cart::where('user_id', $user->id)->with('cartItem.variant.product')->first();

            if (!$cart || $cart->cartItem->isEmpty()) {
                return redirect()->route('client.carts')->with('error', 'Giỏ hàng của bạn đang trống!');
            }

            return view('clients.carts.checkout', compact('brands', 'categories', 'cart'));
        } catch (\Exception $e) {
            \Log::error('Checkout error: ' . $e->getMessage());
            return redirect()->route('client.carts')->with('error', 'Có lỗi xảy ra: ' . $e->getMessage());
        }
    }


    public function showProfile()
    {
        $user = Auth::user();
        $brands = Brand::all();
        $categories = Category::all();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Bạn phải đăng nhập mới xem được thông tin');
        }
        return view('clients.profiles.profile', compact('user', 'brands', 'categories'));
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
        ]);

        try {
            $user->update($validated);

            return redirect()->back()->with('success', 'Cập nhật thông tin thành công!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Đã xảy ra lỗi. Vui lòng thử lại!');
        }
    }

    public function showChangePasswordForm()
    {
        $brands = Brand::all();
        $categories = Category::all();

        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Bạn phải đăng nhập mới được đổi mật khẩu');
        }

        return view('clients.profiles.change', compact('brands', 'categories', 'user'));
    }

    // Xử lý đổi mật khẩu
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|string|min:8|confirmed',
        ], [
            'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại',
            'new_password.required' => 'Vui lòng nhập mật khẩu mới',
            'new_password.min' => 'Mật khẩu mới phải có ít nhất 8 ký tự',
            'new_password.confirmed' => 'Xác nhận mật khẩu mới không khớp',
        ]);

        $user = Auth::user();
        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'Mật khẩu hiện tại không đúng']);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return back()->with('success', 'Đổi mật khẩu thành công!');
    }

    public function orderList()
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Bạn phải đăng nhập mới được vào được trang đơn hàng');
        }
        $orders = Order::where('user_id', $user->id)->orderByDesc('created_at')->get();
        $categories = Category::all();
        $brands = Brand::all();

        // Thống kê cho client
        $totalOrders = $orders->count();
        $totalSpent = $orders->sum('total_price');
        $completedOrders = $orders->where('status_order', 'completed')->count();
        $pendingOrders = $orders->where('status_order', 'pending')->count();

        // Thống kê theo tháng (6 tháng gần nhất)
        $monthlyStats = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthOrders = $orders->filter(function ($order) use ($month) {
                return $order->created_at->format('Y-m') === $month->format('Y-m');
            });
            
            $monthlyStats[] = [
                'month' => $month->format('M Y'),
                'orders' => $monthOrders->count(),
                'total' => $monthOrders->sum('total_price')
            ];
        }

        return view('clients.orders.orderlist', compact(
            'orders', 
            'categories', 
            'brands', 
            'user',
            'totalOrders',
            'totalSpent',
            'completedOrders',
            'pendingOrders',
            'monthlyStats'
        ));
    }

    public function orderDetail($id)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Vui lòng đăng nhập để tiếp tục.');
        }
        $order = Order::with(['OrderDetail.variant.product.images', 'user'])
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $categories = Category::all();
        $brands = Brand::all();
        return view('clients.orders.orderdetail', compact('order', 'categories', 'brands'));
    }

    public function cancelOrder(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'cancel_reason' => 'required|string|max:500',
        ]);

        $order = Order::findOrFail($request->order_id);
        
        // Kiểm tra xem đơn hàng có thuộc về user hiện tại không
        if ($order->user_id !== Auth::id()) {
            return redirect()->back()->with('error', 'Bạn không có quyền hủy đơn hàng này.');
        }

        // Chỉ cho phép hủy đơn hàng khi đang ở trạng thái pending
        if ($order->status_order !== 'pending') {
            return redirect()->back()->with('error', 'Chỉ có thể hủy đơn hàng khi đang chờ xác nhận.');
        }

        $order->update([
            'status_order' => 'cancelled',
            'cancel_reason' => $request->cancel_reason,
        ]);

        return redirect()->back()->with('success', 'Đơn hàng đã được hủy thành công.');
    }

    public function completeOrder($id)
    {
        $order = Order::findOrFail($id);
        
        // Kiểm tra xem đơn hàng có thuộc về user hiện tại không
        if ($order->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền hoàn thành đơn hàng này.'
            ], 403);
        }

        // Chỉ cho phép hoàn thành đơn hàng khi đã nhận hàng
        if ($order->status_order !== 'received') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể hoàn thành đơn hàng khi đã nhận hàng.'
            ], 400);
        }

        $order->update([
            'status_order' => 'completed',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Đơn hàng đã được hoàn thành thành công.'
        ]);
    }

    public function placeOrder(Request $request)
    {
        // Laravel validation thay vì HTML validation
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'number' => 'required|string|max:20',
            'add1' => 'required|string|max:500',
            'city' => 'required|string|max:255',
            'message' => 'nullable|string|max:1000',
            'payment_method' => 'required|in:cod,vnpay',
            'accept_terms' => 'required|accepted',
        ], [
            'name.required' => 'Vui lòng nhập họ và tên.',
            'name.max' => 'Họ và tên không được vượt quá 255 ký tự.',
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không đúng định dạng.',
            'email.max' => 'Email không được vượt quá 255 ký tự.',
            'number.required' => 'Vui lòng nhập số điện thoại.',
            'number.max' => 'Số điện thoại không được vượt quá 20 ký tự.',
            'add1.required' => 'Vui lòng nhập địa chỉ giao hàng.',
            'add1.max' => 'Địa chỉ không được vượt quá 500 ký tự.',
            'city.required' => 'Vui lòng nhập tỉnh/thành phố.',
            'city.max' => 'Tỉnh/thành phố không được vượt quá 255 ký tự.',
            'message.max' => 'Ghi chú không được vượt quá 1000 ký tự.',
            'payment_method.required' => 'Vui lòng chọn phương thức thanh toán.',
            'payment_method.in' => 'Phương thức thanh toán không hợp lệ.',
            'accept_terms.required' => 'Vui lòng đồng ý với điều khoản & chính sách.',
            'accept_terms.accepted' => 'Vui lòng đồng ý với điều khoản & chính sách.',
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return redirect()->route('login')->with('error', 'Vui lòng đăng nhập để đặt hàng.');
            }

            // Kiểm tra tài khoản có bị khóa không
            if (!$user->is_active) {
                return redirect()->route('client.carts.checkout')->with('error', 'Tài khoản của bạn đã bị khóa, không thể mua hàng nữa.');
            }

            $cart = Cart::where('user_id', $user->id)->with('cartItem.variant.product')->first();

            if (!$cart || $cart->cartItem->isEmpty()) {
                return redirect()->route('client.carts')->with('error', 'Giỏ hàng của bạn đang trống!');
            }

            // Tính toán tổng tiền
            $cartTotal = $cart->total_price ?? 0;
            $shipping = 50000;
            $coupon = session('applied_coupon');
            $discount = $coupon['discount'] ?? 0;
            $finalTotal = $cartTotal + $shipping - $discount;

            // Tạo đơn hàng
            $order = Order::create([
                'user_id' => $user->id,
                'user_name' => $validated['name'],
                'user_email' => $validated['email'],
                'user_phone' => $validated['number'],
                'user_address' => $validated['add1'],
                'user_city' => $validated['city'],
                'user_note' => $validated['message'],
                'total_price' => $finalTotal,
                'discount' => $discount,
                'coupon_code' => $coupon['code'] ?? null,
                'type_payment' => $validated['payment_method'],
                'status_order' => 'pending',
                'status_payment' => $validated['payment_method'] === 'vnpay' ? 'unpaid' : 'unpaid',
            ]);

            // Tạo chi tiết đơn hàng
            foreach ($cart->cartItem as $item) {
                $product = $item->variant->product;
                $basePrice = $product ? ($product->base_price ?? 0) : 0;
                $variantPrice = $item->variant->price ?? 0;
                $unitPrice = $basePrice + $variantPrice;
                $totalPrice = $unitPrice * $item->quantity;

                OrderDetail::create([
                    'order_id' => $order->id,
                    'variant_id' => $item->variant_id,
                    'quantity' => $item->quantity,
                    'variant_price' => $variantPrice,
                    'total_price' => $totalPrice,
                ]);
            }

            // Xóa giỏ hàng
            $cart->cartItem()->delete();
            $cart->delete();

            // Xóa coupon session
            session()->forget('applied_coupon');

            // Xử lý thanh toán
            if ($validated['payment_method'] === 'vnpay') {
                return redirect()->route('vnpay.payment', ['order_id' => $order->id]);
            } else {
                return redirect()->route('client.orders')->with('success', 'Đặt hàng thành công! Chúng tôi sẽ liên hệ với bạn sớm nhất.');
            }

        } catch (\Exception $e) {
            \Log::error('Place order error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Có lỗi xảy ra khi đặt hàng. Vui lòng thử lại.');
        }
    }


    public function useCoupon(Request $request)
    {
        $code = $request->input('coupon_code');
        $coupon = Coupon::where('code', $code)->where('status', 1)->first();

        if (!$coupon) {
            return response()->json(['error' => 'Mã giảm giá không hợp lệ hoặc đã hết hạn.']);
        }

        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Bạn cần đăng nhập để sử dụng mã giảm giá.']);
        }

        $cart = Cart::with('cartItem.variant.product')->where('user_id', $user->id)->first();
        if (!$cart || $cart->cartItem->isEmpty()) {
            return response()->json(['error' => 'Giỏ hàng trống.']);
        }

        $totalPrice = $cart->total_price;
        $discount = 0;

        if ($totalPrice < $coupon->min_order_value) {
            return response()->json(['error' => 'Đơn hàng chưa đạt giá trị tối thiểu để dùng mã.']);
        }

        if ($coupon->type === 'percent') {
            $discount = $totalPrice * ($coupon->value / 100);
        } elseif ($coupon->type === 'fixed') {
            $discount = $coupon->value;
        }

        if ($coupon->max_discount && $discount > $coupon->max_discount) {
            $discount = $coupon->max_discount;
        }

        session()->put('applied_coupon', [
            'code' => $coupon->code,
            'discount' => $discount,
        ]);

        return response()->json([
            'success' => 'Áp dụng mã thành công!',
            'discount' => $discount,
            'formatted_discount' => number_format($discount, 0, ',', '.'),
            'total' => number_format($totalPrice + 50000 - $discount, 0, ',', '.')
        ]);
    }


    public function removeCoupon()
    {
        session()->forget('applied_coupon');

        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Bạn cần đăng nhập.']);
        }

        $cart = Cart::with('cartItem')->where('user_id', $user->id)->first();
        $total = ($cart->total_price ?? 0) + 50000;

        return response()->json([
            'success' => 'Đã huỷ mã giảm giá.',
            'discount' => 0,
            'formatted_discount' => number_format(0, 0, ',', '.'),
            'total' => number_format($total, 0, ',', '.')
        ]);
    }


    public function addReview(Request $request, $id) // $id là product_id
    {
        $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Bạn phải đăng nhập mới được bình luận');
        }
        // Kiểm tra xem user đã từng mua sản phẩm chưa
        $hasPurchased = Order::where('user_id', $user->id)
            ->whereIn('status_order', ['completed', 'received']) // các trạng thái đã nhận hàng
            ->whereHas('orderDetail.variant', function ($q) use ($id) {
                $q->where('product_id', $id);
            })
            ->exists();

        if (!$hasPurchased) {
            return back()->with('error', 'Bạn chỉ có thể đánh giá sau khi đã mua sản phẩm.');
        }

        // Kiểm tra nếu user đã đánh giá rồi thì không cho đánh giá lại (nếu muốn)
        $alreadyReviewed = Review::where('user_id', $user->id)
            ->where('product_id', $id)
            ->exists();

        if ($alreadyReviewed) {
            return back()->with('error', 'Bạn đã đánh giá sản phẩm này rồi.');
        }

        Review::create([
            'user_id'    => $user->id,
            'product_id' => $id,
            'rating'     => $request->rating,
            'comment'    => $request->comment,
        ]);

        return back()->with('success', 'Đánh giá của bạn đã được gửi.');
    }
}
