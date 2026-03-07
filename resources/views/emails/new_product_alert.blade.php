<!DOCTYPE html>
<html>

<body style="font-family: Arial, sans-serif; text-align: center; color: #333;">
    <h2>Discover Our Newest Collection!</h2>
    <img src="{{ $product->image }}" alt="{{ $product->name }}"
        style="width: 300px; border-radius: 10px; margin-bottom: 20px;">
    <h3>{{ $product->name }}</h3>
    <p style="color: #666; max-width: 500px; margin: 0 auto;">{{ Str::limit($product->description, 100) }}</p>
    <br>
    <a href="{{ config('app.frontend_url') }}/product/{{ $product->id }}"
        style="background-color: #000; color: #fff; padding: 10px 20px; text-decoration: none; font-weight: bold; border-radius: 5px;">Shop
        Now</a>
    <br><br>
    <p style="font-size: 10px; color: #999;">You received this because you subscribed to Solher Bag.</p>
</body>

</html>
