<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CR Delivery - اطلب وصل لحد بابك</title>
    <meta name="description" content="تطبيق CR Delivery - اطلب من أفضل المطاعم والمقاهي واستمتع بتوصيل سريع وآمن">
    <style>
        :root {
            --gold: #FCD34D;
            --gold-dark: #F59E0B;
            --bg-dark: #0F0F0F;
            --bg-card: #1F1F1F;
            --bg-hover: #2D2D2D;
            --text-primary: #FFFFFF;
            --text-secondary: #D1D5DB;
            --accent-blue: #3B82F6;
            --accent-green: #10B981;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            line-height: 1.6;
        }

        /* Hero Section */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--bg-dark) 0%, #1a1a1a 100%);
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            opacity: 0.5;
        }

        .hero__content {
            max-width: 1200px;
            width: 100%;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            align-items: center;
        }

        @media (max-width: 768px) {
            .hero__content {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
        }

        .hero__text {
            animation: slideInLeft 0.8s ease-out;
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .logo {
            height: 80px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
        }

        .logo img {
            height: 100%;
            width: auto;
        }

        h1 {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--text-primary), var(--gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -1px;
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 2.5rem;
            }
        }

        .subtitle {
            font-size: 1.3rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
            max-width: 500px;
            line-height: 1.8;
        }

        .cta-text {
            font-size: 0.95rem;
            color: var(--text-secondary);
            margin-top: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Screenshots Section */
        .hero__images {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            animation: slideInRight 0.8s ease-out;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .screenshot {
            height: 520px;
            width: auto;
            border-radius: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            object-fit: cover;
        }

        .screenshot:hover {
            transform: translateY(-10px);
            box-shadow: 0 30px 80px rgba(252, 211, 77, 0.2);
        }

        .screenshot:nth-child(2) {
            display: none;
        }

        .screenshot:nth-child(3) {
            display: none;
        }

        @media (max-width: 768px) {
            .screenshot {
                height: 400px;
            }
        }

        /* QR Codes Section */
        .qr-section {
            background: linear-gradient(180deg, #1a1a1a 0%, var(--bg-dark) 100%);
            padding: 4rem 2rem;
            border-top: 1px solid rgba(252, 211, 77, 0.1);
        }

        .qr-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .qr-title {
            text-align: center;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-primary);
        }

        .qr-subtitle {
            text-align: center;
            color: var(--text-secondary);
            margin-bottom: 3rem;
            font-size: 1.1rem;
        }

        .qr-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            max-width: 900px;
            margin: 0 auto;
        }

        @media (max-width: 768px) {
            .qr-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
        }

        .qr-card {
            background: var(--bg-card);
            border: 1px solid rgba(252, 211, 77, 0.2);
            border-radius: 20px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .qr-card::before {
            content: '';
            position: absolute;
            top: -1px;
            left: -1px;
            right: -1px;
            bottom: -1px;
            background: linear-gradient(135deg, rgba(252, 211, 77, 0.3), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
        }

        .qr-card:hover {
            border-color: var(--gold);
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(252, 211, 77, 0.15);
        }

        .qr-card:hover::before {
            opacity: 1;
        }

        .qr-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .qr-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--gold);
        }

        .qr-card p {
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }

        .qr-image {
            background: var(--text-primary);
            padding: 1rem;
            border-radius: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .qr-image img {
            width: 200px;
            height: 200px;
            display: block;
        }

        .qr-download {
            display: inline-block;
            margin-top: 1rem;
            padding: 0.5rem 1rem;
            background: rgba(252, 211, 77, 0.1);
            border: 1px solid var(--gold);
            color: var(--gold);
            border-radius: 8px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .qr-download:hover {
            background: var(--gold);
            color: var(--bg-dark);
        }

        /* Features Section */
        .features {
            background: var(--bg-dark);
            padding: 4rem 2rem;
            border-top: 1px solid rgba(252, 211, 77, 0.1);
        }

        .features-grid {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            background: var(--bg-card);
            padding: 2rem;
            border-radius: 15px;
            border: 1px solid rgba(252, 211, 77, 0.1);
            transition: all 0.3s ease;
        }

        .feature-card:hover {
            border-color: var(--gold);
            transform: translateY(-3px);
        }

        .feature-card h4 {
            color: var(--gold);
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .feature-card p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.6;
        }

        /* Footer */
        .footer {
            background: linear-gradient(180deg, rgba(0,0,0,0), #000);
            border-top: 1px solid rgba(252, 211, 77, 0.1);
            padding: 2rem;
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .footer a {
            color: var(--gold);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer a:hover {
            color: var(--gold-dark);
        }

        /* Animations */
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.7;
            }
        }

        .qr-card {
            animation: fadeInUp 0.6s ease-out;
        }

        .qr-card:nth-child(2) {
            animation-delay: 0.2s;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Scroll reveal */
        .reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s ease-out;
        }

        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }

        /* Accessibility */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            h1 {
                font-size: 2.8rem;
            }

            .subtitle {
                font-size: 1.1rem;
            }

            .qr-title {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <section class="hero">
        <div class="hero__content">
            <div class="hero__text">
                <div class="logo">
                    <img src="/landing/logo.png" alt="CR Delivery Logo">
                </div>
                <h1>اطلب وصل لحد بابك</h1>
                <p class="subtitle">اطلب من أفضل المطاعم والمقاهي واستمتع بتوصيل سريع وآمن مع تطبيق CR Delivery</p>
                <p class="cta-text">
                    <span>↓</span> اختر متجرك المفضل وابدأ الآن
                </p>
            </div>
            <div class="hero__images">
                <img src="/landing/shot-1.webp" alt="CR Delivery App Screenshot 1" class="screenshot">
                <img src="/landing/shot-2.webp" alt="CR Delivery App Screenshot 2" class="screenshot">
                <img src="/landing/shot-3.webp" alt="CR Delivery App Screenshot 3" class="screenshot">
                <img src="/landing/shot-4.webp" alt="CR Delivery App Screenshot 4" class="screenshot">
            </div>
        </div>
    </section>

    <!-- QR Codes Section -->
    <section class="qr-section">
        <div class="qr-container">
            <h2 class="qr-title">حمل التطبيق الآن</h2>
            <p class="qr-subtitle">اختر المتجر المفضل لديك وابدأ بتحميل التطبيق</p>

            <div class="qr-grid">
                <!-- Google Play -->
                <div class="qr-card reveal">
                    <div class="qr-icon">🔵</div>
                    <h3>Google Play</h3>
                    <p>متجر جوجل بلاي</p>
                    <div class="qr-image">
                        <canvas id="qr-google"></canvas>
                    </div>
                    <a href="https://play.google.com/store/apps/details?id=com.cr7delivery.cr7_delivery"
                       target="_blank" rel="noopener noreferrer" class="qr-download">
                        تحميل من Google Play
                    </a>
                </div>

                <!-- Apple App Store -->
                <div class="qr-card reveal">
                    <div class="qr-icon">🍎</div>
                    <h3>App Store</h3>
                    <p>متجر آبل أب ستور</p>
                    <div class="qr-image">
                        <canvas id="qr-apple"></canvas>
                    </div>
                    <a href="https://apps.apple.com/ae/app/cr-delivery/id6815852436"
                       target="_blank" rel="noopener noreferrer" class="qr-download">
                        تحميل من App Store
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features">
        <div class="features-grid">
            <div class="feature-card reveal">
                <h4>⚡ توصيل سريع</h4>
                <p>وصول طعامك الساخن والطازج لحد باب منزلك في أسرع وقت</p>
            </div>
            <div class="feature-card reveal">
                <h4>🎯 مطاعم متنوعة</h4>
                <p>اختر من آلاف المطاعم والمقاهي والتاجر المفضل لديك</p>
            </div>
            <div class="feature-card reveal">
                <h4>💰 عروض وخصومات</h4>
                <p>استمتع بأفضل العروض والخصومات الحصرية على التطبيق</p>
            </div>
            <div class="feature-card reveal">
                <h4>🔒 آمن وموثوق</h4>
                <p>تتبع طلبك في الوقت الفعلي مع خدمة عملاء على مدار الساعة</p>
            </div>
            <div class="feature-card reveal">
                <h4>🚴 متوصلينا محترفون</h4>
                <p>توصيل آمن وسريع من قبل عاملين مدربين وملتزمين</p>
            </div>
            <div class="feature-card reveal">
                <h4>📱 تطبيق سهل الاستخدام</h4>
                <p>واجهة بسيطة وسهلة تجعل الطلب والدفع أكثر راحة</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <p>&copy; 2026 CR Delivery. جميع الحقوق محفوظة.</p>
        <p style="margin-top: 1rem; font-size: 0.85rem;">
            <a href="#">سياسة الخصوصية</a> •
            <a href="#">شروط الاستخدام</a> •
            <a href="#">اتصل بنا</a>
        </p>
    </footer>

    <!-- QR Code Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <script>
        // Generate QR Codes
        const googlePlayUrl = 'https://play.google.com/store/apps/details?id=com.cr7delivery.cr7_delivery';
        const appStoreUrl = 'https://apps.apple.com/ae/app/cr-delivery/id6815852436';

        // Generate Google Play QR Code
        new QRCode(document.getElementById('qr-google'), {
            text: googlePlayUrl,
            width: 200,
            height: 200,
            colorDark: '#0F0F0F',
            colorLight: '#FFFFFF',
            correctLevel: QRCode.CorrectLevel.H
        });

        // Generate Apple App Store QR Code
        new QRCode(document.getElementById('qr-apple'), {
            text: appStoreUrl,
            width: 200,
            height: 200,
            colorDark: '#0F0F0F',
            colorLight: '#FFFFFF',
            correctLevel: QRCode.CorrectLevel.H
        });

        // Intersection Observer for scroll reveal
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

        // Mobile screenshot carousel
        let currentShot = 0;
        const screenshots = document.querySelectorAll('.screenshot');

        if (window.innerWidth <= 768) {
            screenshots.forEach((shot, idx) => {
                if (idx !== 0) shot.style.display = 'none';
            });
        }
    </script>
</body>
</html>