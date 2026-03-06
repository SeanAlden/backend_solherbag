<!DOCTYPE html>
<html>
<head>
    <title>Response from Solher</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Hello, {{ $contact->name }}</h2>
    <p>Thank you for reaching out to us. Here is the response to your inquiry:</p>

    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #000; margin-bottom: 20px;">
        <p><strong>Your Message:</strong></p>
        <p><em>"{{ $contact->description }}"</em></p>
    </div>

    <div style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 8px;">
        <p><strong>Admin Response:</strong></p>
        <p style="white-space: pre-wrap;">{{ $contact->response }}</p>
    </div>

    <br>
    <p>Best Regards,<br><strong>Solher Bag Support Team</strong><br>solherbag@gmail.com</p>
</body>
</html>
