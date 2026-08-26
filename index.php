<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renee Farms | Precision Agriculture & Livestock Intelligence</title>
    <meta name="theme-color" content="#1b4332">
    <meta name="description" content="Renee Farms blends sustainable livestock care with data-driven operations for healthier food systems.">

    <link rel="icon" href="assets/images/favicon.ico?v=2024.06.01" type="image/x-icon" sizes="any">
    <link rel="apple-touch-icon" href="assets/images/favicon.ico?v=2024.06.01">

    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="dns-prefetch" href="https://fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-1: #081c15;
            --bg-2: #1b4332;
            --brand: #2d6a4f;
            --brand-2: #40916c;
            --accent: #95d5b2;
            --accent-warm: #f6c453;
            --text: #0f172a;
            --muted: #475569;
            --white: #ffffff;
            --glass: rgba(255, 255, 255, 0.84);
            --ring: rgba(64, 145, 108, 0.22);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, Segoe UI, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 8% 12%, rgba(116, 198, 157, 0.3), transparent 31%),
                radial-gradient(circle at 95% 18%, rgba(246, 196, 83, 0.18), transparent 28%),
                radial-gradient(circle at 80% 92%, rgba(45, 106, 79, 0.14), transparent 34%),
                linear-gradient(145deg, #f2fbf6 0%, #fbfefc 52%, #e8f4ed 100%);
            min-height: 100vh;
        }

        .container {
            width: min(1140px, calc(100% - 2.4rem));
            margin: 0 auto;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            backdrop-filter: blur(14px);
            background: rgba(244, 251, 247, 0.82);
            border-bottom: 1px solid rgba(27, 67, 50, 0.08);
            box-shadow: 0 10px 30px rgba(12, 52, 36, 0.06);
        }

        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.9rem 0;
        }

        .brand {
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            color: inherit;
        }

        .brand img {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(27, 67, 50, 0.14);
        }

        .brand h1 {
            margin: 0;
            font-size: 0.95rem;
            letter-spacing: 0.6px;
            font-weight: 800;
        }

        .brand small {
            color: var(--muted);
            font-size: 0.74rem;
        }

        .btn {
            border: none;
            text-decoration: none;
            border-radius: 999px;
            padding: 0.72rem 1.1rem;
            font-weight: 600;
            transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary {
            color: var(--white);
            background: linear-gradient(135deg, var(--brand), var(--brand-2));
            box-shadow: 0 12px 28px rgba(45, 106, 79, 0.28);
        }

        .btn-light {
            color: var(--bg-2);
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.45);
        }

        .hero {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            align-items: center;
            gap: clamp(1.8rem, 4vw, 3rem);
            padding: clamp(3.8rem, 7vw, 6.6rem) 0 3.2rem;
        }

        .kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            border-radius: 999px;
            background: linear-gradient(135deg, rgba(45, 106, 79, 0.12), rgba(149, 213, 178, 0.24));
            color: #1f513c;
            padding: 0.48rem 0.84rem;
            font-weight: 700;
            font-size: 0.84rem;
            letter-spacing: 0.01em;
            border: 1px solid rgba(45, 106, 79, 0.18);
            box-shadow: 0 8px 20px rgba(12, 52, 36, 0.08);
        }

        .hero h4 {
            margin: 1.1rem 0 0.9rem;
            font-size: clamp(1.25rem, 2.2vw, 1.75rem);
            line-height: 1.05;
            letter-spacing: -0.02em;
            color: #0f2b1f;
            text-wrap: balance;
        }

        .hero p {
            margin: 0;
            color: #415d51;
            font-size: 1.06rem;
            max-width: 62ch;
            line-height: 1.5;
        }

        .cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-top: 1.5rem;
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.8rem;
            margin-top: 1.7rem;
        }

        .metric {
            padding: 0.8rem;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(45, 106, 79, 0.12);
            box-shadow: 0 5px 14px rgba(12, 52, 36, 0.08);
        }

        .metric strong {
            display: block;
            font-size: 1.15rem;
            color: #113826;
        }

        .metric span {
            font-size: 0.82rem;
            color: #58756a;
        }

        .visual {
            border-radius: 24px;
            overflow: hidden;
            position: relative;
            box-shadow: 0 24px 60px rgba(12, 52, 36, 0.24);
            background:
                linear-gradient(160deg, rgba(8, 28, 21, 0.68), rgba(27, 67, 50, 0.82)),
                linear-gradient(160deg, var(--bg-1), var(--bg-2));
            min-height: 490px;
            display: grid;
            place-items: center;
            isolation: isolate;
        }

        .visual::before {
            content: "";
            position: absolute;
            inset: 14px;
            border: 1px solid rgba(255, 255, 255, 0.24);
            border-radius: 18px;
            pointer-events: none;
        }

        .slideshow-frame {
            position: relative;
            z-index: 1;
            width: min(88%, 520px);
            aspect-ratio: 4 / 3;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 16px 46px rgba(0, 0, 0, 0.36);
            background: linear-gradient(135deg, rgba(255,255,255,0.18), rgba(255,255,255,0.05));
        }

        .slideshow-frame img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0;
            transform: scale(1.025);
            transition: opacity .7s ease, transform 5s ease;
            will-change: opacity, transform;
        }

        .slideshow-frame img.is-active {
            opacity: 1;
            transform: scale(1);
            z-index: 1;
        }

        .slideshow-frame img.is-next {
            z-index: 2;
        }

        @media (prefers-reduced-motion: reduce) {
            .slideshow-frame img {
                transition: opacity .2s ease;
                transform: none;
            }

            .btn:hover { transform: none; }
        }

        .floating-card {
            position: absolute;
            z-index: 5;
            bottom: 1rem;
            left: 1.5rem;
            right: 1.5rem;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.72);
            box-shadow: 0 10px 28px rgba(6, 31, 22, 0.2);
            padding: 0.85rem 1rem;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.85rem;
        }

        .floating-card div { min-width: 0; }
        .floating-card strong { display: block; color: #113826; font-size: 0.98rem; }
        .floating-card span { font-size: 0.76rem; color: #527063; }

        .section-grid {
            padding: 1.6rem 0 4rem;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .feature {
            position: relative;
            overflow: hidden;
            background: rgba(255,255,255,0.82);
            border: 1px solid rgba(45,106,79,0.14);
            border-radius: 18px;
            padding: 1.35rem;
            box-shadow: 0 14px 30px rgba(12, 52, 36, 0.08);
        }

        .feature::before {
            content: attr(data-index);
            display: inline-grid;
            place-items: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 12px;
            color: #123826;
            background: linear-gradient(135deg, rgba(149, 213, 178, 0.62), rgba(255, 255, 255, 0.82));
            font-weight: 800;
            font-size: 0.86rem;
            border: 1px solid rgba(45, 106, 79, 0.12);
        }

        .feature h3 {
            margin: 0.45rem 0;
            font-size: 1.05rem;
            color: #163d2c;
        }

        .feature p {
            margin: 0;
            color: #4c685d;
            font-size: 0.95rem;
        }

        .footer {
            border-top: 1px solid rgba(27, 67, 50, 0.08);
            padding: 1rem 0 1.6rem;
            color: #4a665b;
            font-size: 0.86rem;
            text-align: center;
        }

        @media (max-width: 980px) {
            .hero,
            .section-grid {
                grid-template-columns: 1fr;
            }

            .visual {
                min-height: 420px;
            }

            .floating-card {
                left: 1rem;
                right: 1rem;
            }

            .metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 560px) {
            .container { width: min(1140px, calc(100% - 1.4rem)); }
            .metrics { grid-template-columns: 1fr; }
            .brand h1 { font-size: 0.82rem; }
            .brand small { font-size: 0.7rem; }
            .nav { padding: 0.8rem 0; }
            .btn { padding: 0.65rem 1rem; }
            .visual { min-height: 430px; }
            .slideshow-frame { width: min(86%, 360px); }
            .floating-card {
                grid-template-columns: 1fr;
                padding: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container nav">
            <a class="brand" href="index.php" aria-label="Renee Farms home">
                <img src="assets/images/logo.jpg?v=2024.06.01" alt="Renee Farms logo" width="42" height="42" decoding="async">
                <div>
                    <h1>RENEE FARMS LTD</h1>
                    <small>Eat Healthy With Renee Farms</small>
                </div>
            </a>
            <a class="btn btn-primary" href="sign.php">Launch farm portal</a>
        </div>
    </header>

    <main class="container">
        <section class="hero">
            <div>
                <span class="kicker" aria-label="Smart, sustainable, scalable"><span aria-hidden="true">🟢</span> Smart <span aria-hidden="true">•</span> Sustainable <span aria-hidden="true">•</span> Scalable</span>
                <h4>Welcome to Renee Smart System — where advanced farm operations meet intelligent livestock management.</h4>
                <p>
                    From poultry and ruminant performance to inventory forecasting and expense intelligence,
                    Renee Farms delivers a modern agricultural ecosystem built for productivity, transparency,
                    and long-term ecological stewardship.
                </p>
                <p style="margin-top:1.25rem;color:#365446;font-weight:500;">Use <strong>Launch farm portal</strong> in the top navigation to access your workspace.</p>
                <div class="metrics" role="list" aria-label="Farm highlights">
                    <article class="metric" role="listitem"><strong>24/7</strong><span>Operational visibility</span></article>
                    <article class="metric" role="listitem"><strong>Data-first</strong><span>Production decisions</span></article>
                    <article class="metric" role="listitem"><strong>Eco-led</strong><span>Farm sustainability model</span></article>
                </div>
            </div>

            <aside class="visual" aria-label="Featured farm visual">
                <div class="slideshow-frame">
                    <img
                        id="hero-slideshow-image-current"
                        class="is-active"
                        src="assets/images/chick.png?v=2024.06.01"
                        alt="Chick standing in farm grass"
                        width="420"
                        height="420"
                        fetchpriority="high"
                        decoding="async"
                    >
                    <img
                        id="hero-slideshow-image-next"
                        src="assets/images/chick.png?v=2024.06.01"
                        alt=""
                        width="420"
                        height="420"
                        loading="eager"
                        decoding="async"
                        aria-hidden="true"
                    >
                </div>
                <div class="floating-card" aria-hidden="true">
                    <div><strong>+18% Yield</strong><span>Feed optimization</span></div>
                    <div><strong>Live Dashboards</strong><span>Operational analytics</span></div>
                    <div><strong>Risk Alerts</strong><span>Faster interventions</span></div>
                </div>
            </aside>
        </section>

        <section class="section-grid" aria-label="Core capabilities">
            <article class="feature" data-index="01">
                <h3>Integrated Production Hub</h3>
                <p>Track broilers, layers, and ruminants from intake to output in one coherent digital workflow.</p>
            </article>
            <article class="feature" data-index="02">
                <h3>Financial Command Layer</h3>
                <p>Connect expense records, sales, and stock movement to understand true farm profitability in real time.</p>
            </article>
            <article class="feature" data-index="03">
                <h3>Operational Intelligence</h3>
                <p>Turn raw records into smart planning signals that strengthen consistency, quality, and growth.</p>
            </article>
        </section>
    </main>

    <footer class="footer">
        <div class="container">&copy; 2026 Renee Farms Ltd. All rights reserved.</div>
    </footer>

    <script>
        (function () {
            const currentImage = document.getElementById('hero-slideshow-image-current');
            const nextImage = document.getElementById('hero-slideshow-image-next');
            if (!currentImage || !nextImage) return;

            const slides = [
                { src: 'assets/images/chick.png?v=2024.06.01', alt: 'Chick standing in farm grass' },
                { src: 'assets/images/bookkeeping.jpg?v=2024.06.01', alt: 'Farm bookkeeping and financial records' },
                { src: 'assets/images/eggs.jpg?v=2024.06.01', alt: 'Fresh farm eggs in a basket' },
                { src: 'assets/images/cow.jpg?v=2024.06.01', alt: 'Cow in a green pasture' },
                { src: 'assets/images/goats.jpg?v=2024.06.01', alt: 'Goat in a farm field' },
                { src: 'assets/images/sheeps2.jpg?v=2024.06.01', alt: 'Sheep grazing on grassland' }
            ];

            const loadedSlides = new Set([slides[0].src]);
            let activeSlideIndex = 0;
            let isTransitioning = false;

            function preloadSlide(slide) {
                if (loadedSlides.has(slide.src)) return Promise.resolve(slide);

                return new Promise(function (resolve, reject) {
                    const image = new Image();
                    image.onload = function () {
                        loadedSlides.add(slide.src);
                        resolve(slide);
                    };
                    image.onerror = reject;
                    image.decoding = 'async';
                    image.src = slide.src;
                });
            }

            function preloadUpcomingSlides() {
                slides.slice(1).forEach(function (slide) {
                    preloadSlide(slide).catch(function () {});
                });
            }

            function showSlide(slideIndex) {
                if (isTransitioning || document.hidden) return;

                const slide = slides[slideIndex];
                isTransitioning = true;

                preloadSlide(slide)
                    .then(function () {
                        nextImage.src = slide.src;
                        nextImage.alt = slide.alt;
                        nextImage.removeAttribute('aria-hidden');
                        nextImage.classList.add('is-next', 'is-active');

                        window.setTimeout(function () {
                            currentImage.src = slide.src;
                            currentImage.alt = slide.alt;
                            nextImage.classList.remove('is-next', 'is-active');
                            nextImage.setAttribute('aria-hidden', 'true');
                            activeSlideIndex = slideIndex;
                            isTransitioning = false;
                        }, 760);
                    })
                    .catch(function () {
                        isTransitioning = false;
                    });
            }

            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(preloadUpcomingSlides, { timeout: 1800 });
            } else {
                window.setTimeout(preloadUpcomingSlides, 600);
            }

            window.setInterval(function () {
                const nextSlideIndex = (activeSlideIndex + 1) % slides.length;
                showSlide(nextSlideIndex);
            }, 3600);
        })();
    </script>
</body>
</html>
