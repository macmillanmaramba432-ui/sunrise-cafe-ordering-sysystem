
<?php
session_start();

// Calculate cart item count from the session.
$cartCount = 0;

if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        if (is_array($item)) {
            $cartCount += (int) ($item['quantity'] ?? 0);
        } else {
            $cartCount += (int) $item;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sunrise Café | Fresh Food & Coffee</title>

    <!-- CSS IS INCLUDED IN THIS FILE -->
    <style>
        :root {
            --primary: #b95624;
            --primary-dark: #853917;
            --cream: #fff7ec;
            --dark: #292019;
            --text: #706258;
            --white: #ffffff;
            --border: #efdfd0;
            --shadow: 0 12px 35px rgba(55, 35, 20, .12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 90px;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: var(--dark);
            background: white;
            line-height: 1.6;
        }

        img {
            display: block;
            max-width: 100%;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button {
            font: inherit;
        }

        .container {
            width: min(1160px, 92%);
            margin: auto;
        }

        /* NAVIGATION */

        .site-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, .97);
            border-bottom: 1px solid var(--border);
        }

        .navbar {
            min-height: 78px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .logo {
            font-size: 23px;
            font-weight: 800;
            white-space: nowrap;
        }

        .logo span {
            color: var(--primary);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 28px;
        }

        .nav-links a {
            color: var(--text);
            font-size: 14px;
            font-weight: 700;
        }

        .nav-links a:hover,
        .nav-links .active {
            color: var(--primary);
        }

        .cart-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 23px;
            height: 23px;
            padding: 0 5px;
            border-radius: 30px;
            background: var(--primary);
            color: white;
            font-size: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 13px 22px;
            border: 2px solid transparent;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: .25s ease;
        }

        .btn-primary,
        .nav-button {
            background: var(--primary);
            color: white;
        }

        .nav-button {
            padding: 11px 19px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
        }

        .btn-primary:hover,
        .nav-button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-outline {
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .menu-toggle {
            display: none;
            width: 43px;
            height: 43px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--cream);
            cursor: pointer;
        }

        .menu-toggle span {
            display: block;
            width: 20px;
            height: 2px;
            margin: 5px auto;
            background: var(--dark);
        }

        /* HERO */

        .hero {
            padding: 75px 0;
            overflow: hidden;
            background:
                radial-gradient(circle at 90% 10%, #f6dfbd, transparent 30%),
                linear-gradient(120deg, #fff8ef, #ffefd9);
        }

        .hero-content {
            display: grid;
            grid-template-columns: 1fr .9fr;
            align-items: center;
            gap: 60px;
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 15px;
            color: var(--primary);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 2px;
        }

        .hero h1 {
            margin-bottom: 20px;
            font-size: clamp(40px, 5vw, 65px);
            line-height: 1.12;
            letter-spacing: -2px;
        }

        .hero h1 span {
            color: var(--primary);
        }

        .hero-text p {
            max-width: 510px;
            margin-bottom: 27px;
            color: var(--text);
            line-height: 1.9;
        }

        .hero-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .hero-note {
            margin-top: 22px;
            color: var(--text);
            font-size: 13px;
        }

        .hero-image {
            position: relative;
        }

        .hero-image img {
            width: 100%;
            height: 420px;
            object-fit: cover;
            border-radius: 35% 35% 18px 18px;
            background: #ead8c6;
            box-shadow: var(--shadow);
        }

        .image-label {
            position: absolute;
            bottom: 20px;
            left: -20px;
            padding: 14px 20px;
            border-radius: 12px;
            background: white;
            box-shadow: var(--shadow);
        }

        .image-label small {
            display: block;
            color: var(--text);
        }

        .image-label strong {
            color: var(--primary-dark);
        }

        /* BENEFITS */

        .benefits {
            padding: 27px 0;
            border-bottom: 1px solid var(--border);
        }

        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
        }

        .benefit {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .benefit-icon {
            display: grid;
            place-items: center;
            flex-shrink: 0;
            width: 53px;
            height: 53px;
            border-radius: 14px;
            background: var(--cream);
            font-size: 25px;
        }

        .benefit h3 {
            font-size: 15px;
        }

        .benefit p {
            color: var(--text);
            font-size: 12px;
        }

        /* CATEGORIES */

        .categories-section {
            padding: 80px 0;
        }

        .section-heading {
            max-width: 620px;
            margin: 0 auto 38px;
            text-align: center;
        }

        .section-heading h2 {
            margin-bottom: 12px;
            font-size: clamp(29px, 4vw, 40px);
        }

        .section-heading p {
            color: var(--text);
        }

        .category-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 22px;
        }

        .category-card {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 17px;
            background: white;
            transition: transform .3s, box-shadow .3s;
        }

        .category-card:hover {
            transform: translateY(-7px);
            box-shadow: var(--shadow);
        }

        .category-image {
            height: 210px;
            overflow: hidden;
            background: #f2e4d5;
        }

        .category-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform .4s;
        }

        .category-card:hover .category-image img {
            transform: scale(1.07);
        }

        .category-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding: 17px;
        }

        .category-info h3 {
            font-size: 16px;
        }

        .category-info span {
            color: var(--primary);
            font-size: 12px;
            font-weight: 700;
        }

        .section-action {
            margin-top: 35px;
            text-align: center;
        }

        /* ORDER BANNER */

        .order-banner {
            padding: 55px 0;
            background: var(--primary-dark);
            color: white;
        }

        .order-banner-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
        }

        .order-banner .eyebrow {
            color: #ffdb9d;
        }

        .order-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(26px, 3vw, 36px);
            line-height: 1.3;
        }

        .order-banner p {
            max-width: 650px;
            color: #f8e8dc;
        }

        .btn-light {
            flex-shrink: 0;
            background: white;
            color: var(--primary-dark);
        }

        .btn-light:hover {
            background: var(--cream);
            transform: translateY(-2px);
        }

        /* FOOTER */

        .site-footer {
            padding: 35px 0 22px;
            background: #211a15;
            color: white;
        }

        .footer-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 25px;
        }

        .footer-description {
            margin-top: 7px;
            color: #d6c8bc;
            font-size: 13px;
        }

        .footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: 17px;
        }

        .footer-links a {
            color: #e8dcd2;
            font-size: 13px;
        }

        .footer-links a:hover {
            color: #ffc56f;
        }

        .copyright {
            width: 100%;
            padding-top: 20px;
            border-top: 1px solid #49382c;
            color: #d6c8bc;
            font-size: 12px;
            text-align: center;
        }

        /* REVEAL ANIMATIONS */

        .reveal {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity .6s, transform .6s;
        }

        .reveal.visible {
            opacity: 1;
            transform: translateY(0);
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }

            .reveal {
                opacity: 1;
                transform: none;
            }
        }

        /* TABLETS */

        @media (max-width: 900px) {
            .hero-content {
                gap: 30px;
            }

            .hero-image img {
                height: 350px;
            }

            .category-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .category-image {
                height: 230px;
            }

            .nav-links {
                gap: 15px;
            }
        }

        /* MOBILE */

        @media (max-width: 650px) {
            .navbar {
                min-height: 70px;
                flex-wrap: wrap;
            }

            .logo {
                font-size: 20px;
            }

            .menu-toggle {
                display: block;
                margin-left: auto;
            }

            .nav-button {
                display: none;
            }

            .nav-links {
                position: absolute;
                top: 70px;
                left: 0;
                right: 0;
                display: none;
                flex-direction: column;
                align-items: stretch;
                gap: 0;
                padding: 10px 4% 18px;
                background: white;
                border-bottom: 1px solid var(--border);
                box-shadow: 0 10px 20px #0000000c;
            }

            .nav-links.open {
                display: flex;
            }

            .nav-links a {
                padding: 13px 8px;
                border-bottom: 1px solid #f3eae1;
            }

            .hero {
                padding: 48px 0;
            }

            .hero-content {
                grid-template-columns: 1fr;
                gap: 32px;
            }

            .hero h1 {
                font-size: 43px;
                letter-spacing: -1px;
            }

            .hero-image img {
                height: 310px;
                border-radius: 24px;
            }

            .image-label {
                left: 12px;
                bottom: 12px;
            }

            .benefits-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .categories-section {
                padding: 55px 0;
            }

            .category-grid {
                gap: 13px;
            }

            .category-image {
                height: 155px;
            }

            .category-info {
                align-items: flex-start;
                flex-direction: column;
                padding: 13px;
            }

            .order-banner-content {
                align-items: flex-start;
                flex-direction: column;
            }

            .footer-content {
                align-items: flex-start;
                flex-direction: column;
            }

            .copyright {
                text-align: left;
            }
        }

        @media (max-width: 360px) {
            .hero h1 {
                font-size: 36px;
            }

            .hero-buttons .btn {
                width: 100%;
            }

            .category-grid {
                grid-template-columns: 1fr;
            }

            .category-image {
                height: 220px;
            }
        }
    </style>
</head>

<body>

<header class="site-header">
    <div class="container navbar">

        <a href="index.php" class="logo">
            ☕ Sunrise <span>Café</span>
        </a>

        <button
            class="menu-toggle"
            id="menuToggle"
            type="button"
            aria-label="Open navigation menu"
            aria-expanded="false"
            aria-controls="mainNavigation"
        >
            <span></span>
            <span></span>
            <span></span>
        </button>

        <nav class="nav-links" id="mainNavigation">
            <a href="index.php" class="active">Home</a>
            <a href="menu.php">Our Menu</a>
            <a href="cart.php">
                My Cart
                <span class="cart-count"><?= $cartCount ?></span>
            </a>
            <a href="track_order.php">Track My Order</a>
            <a href="reviews.php">Reviews</a>
        </nav>

        <a href="menu.php" class="nav-button">Order Now</a>
    </div>
</header>

<main>

    <!-- HERO -->
    <section class="hero">
        <div class="container hero-content">

            <div class="hero-text">
                <span class="eyebrow">WELCOME TO SUNRISE CAFÉ</span>

                <h1>
                    Fresh Flavours,<br>
                    <span>Happy Moments.</span>
                </h1>

                <p>
                    Enjoy delicious burgers, handcrafted coffee,
                    fresh pastries and refreshing drinks made with care.
                </p>

                <div class="hero-buttons">
                    <a href="menu.php" class="btn btn-primary">
                        Explore Our Menu →
                    </a>

                    <a href="#categories" class="btn btn-outline">
                        Discover More
                    </a>
                </div>

                <div class="hero-note">
                    Freshly prepared. Carefully served. Made for you.
                </div>
            </div>

            <div class="hero-image">
                <img
                    src="../assets/images/burger.jpg"
                    alt="Fresh Sunrise Café burger"
                    onerror="this.onerror=null;this.src='../assets/images/burger.jpeg';"
                >

                <div class="image-label">
                    <small>Fresh &amp; delicious</small>
                    <strong>Made with love ❤️</strong>
                </div>
            </div>

        </div>
    </section>

    <!-- BENEFITS -->
    <section class="benefits">
        <div class="container benefits-grid">

            <div class="benefit">
                <div class="benefit-icon">🥗</div>
                <div>
                    <h3>Fresh Ingredients</h3>
                    <p>Prepared with care every day.</p>
                </div>
            </div>

            <div class="benefit">
                <div class="benefit-icon">☕</div>
                <div>
                    <h3>Quality Coffee</h3>
                    <p>Your favourite cup, made fresh.</p>
                </div>
            </div>

            <div class="benefit">
                <div class="benefit-icon">🛵</div>
                <div>
                    <h3>Pickup or Delivery</h3>
                    <p>Choose your option at checkout.</p>
                </div>
            </div>

        </div>
    </section>

    <!-- CATEGORIES -->
    <section class="categories-section" id="categories">
        <div class="container">

            <div class="section-heading">
                <span class="eyebrow">WHAT ARE YOU CRAVING?</span>
                <h2>Explore Our Categories</h2>
                <p>
                    Discover something delicious for every mood.
                </p>
            </div>

            <div class="category-grid">

                <a href="menu.php?category=Burgers" class="category-card">
                    <div class="category-image">
                        <img
                            src="../assets/images/burger.jpg"
                            alt="Delicious burger"
                            loading="lazy"
                            onerror="this.onerror=null;this.src='../assets/images/burger.jpeg';"
                        >
                    </div>
                    <div class="category-info">
                        <h3>Burgers</h3>
                        <span>View menu →</span>
                    </div>
                </a>

                <a href="menu.php?category=Coffee" class="category-card">
                    <div class="category-image">
                        <img
                            src="../assets/images/cappuccino.jpg"
                            alt="Fresh cappuccino"
                            loading="lazy"
                            onerror="this.onerror=null;this.src='../assets/images/cappuccino.jpeg';"
                        >
                    </div>
                    <div class="category-info">
                        <h3>Coffee</h3>
                        <span>View menu →</span>
                    </div>
                </a>

                <a href="menu.php?category=Pastries" class="category-card">
                    <div class="category-image">
                        <img
                            src="../assets/images/croissant.jpg"
                            alt="Fresh croissant"
                            loading="lazy"
                            onerror="this.onerror=null;this.src='../assets/images/croissant.jpeg';"
                        >
                    </div>
                    <div class="category-info">
                        <h3>Pastries</h3>
                        <span>View menu →</span>
                    </div>
                </a>

                <a href="menu.php?category=Cold%20Drinks" class="category-card">
                    <div class="category-image">
                        <img
                            src="../assets/images/iced-vanilla-coffee.jpg"
                            alt="Iced vanilla coffee"
                            loading="lazy"
                            onerror="this.onerror=null;this.src='../assets/images/iced-vanilla-coffee.jpeg';"
                        >
                    </div>
                    <div class="category-info">
                        <h3>Cold Drinks</h3>
                        <span>View menu →</span>
                    </div>
                </a>

            </div>

            <div class="section-action">
                <a href="menu.php" class="btn btn-primary">
                    View Full Menu →
                </a>
            </div>

        </div>
    </section>

    <!-- ORDER BANNER -->
    <section class="order-banner">
        <div class="container order-banner-content">

            <div>
                <span class="eyebrow">YOUR NEXT FAVOURITE MEAL</span>
                <h2>Good food is just a few clicks away.</h2>
                <p>
                    Browse the menu, add your favourites to your cart,
                    then choose pickup or delivery at checkout.
                </p>
            </div>

            <a href="menu.php" class="btn btn-light">
                Start Your Order →
            </a>

        </div>
    </section>

</main>

<footer class="site-footer">
    <div class="container footer-content">

        <div>
            <a href="index.php" class="logo">
                ☕ Sunrise <span>Café</span>
            </a>
            <p class="footer-description">
                Fresh flavours and warm moments, every day.
            </p>
        </div>

        <div class="footer-links">
            <a href="index.php">Home</a>
            <a href="menu.php">Menu</a>
            <a href="cart.php">Cart</a>
            <a href="checkout.php">Checkout</a>
            <a href="../admin/index.php">Admin</a>
        </div>

        <p class="copyright">
            &copy; <?= date('Y') ?> Sunrise Café. All rights reserved.
        </p>

    </div>
</footer>

<!-- JAVASCRIPT IS INCLUDED IN THIS FILE -->
<script>
document.addEventListener("DOMContentLoaded", function () {

    // MOBILE NAVIGATION
    const menuToggle = document.getElementById("menuToggle");
    const navigation = document.getElementById("mainNavigation");

    function closeMenu() {
        menuToggle.setAttribute("aria-expanded", "false");
        menuToggle.setAttribute("aria-label", "Open navigation menu");
        navigation.classList.remove("open");
    }

    menuToggle.addEventListener("click", function () {
        const currentlyOpen =
            menuToggle.getAttribute("aria-expanded") === "true";

        menuToggle.setAttribute("aria-expanded", String(!currentlyOpen));
        menuToggle.setAttribute(
            "aria-label",
            currentlyOpen ? "Open navigation menu" : "Close navigation menu"
        );

        navigation.classList.toggle("open", !currentlyOpen);
    });

    navigation.querySelectorAll("a").forEach(function (link) {
        link.addEventListener("click", closeMenu);
    });

    document.addEventListener("click", function (event) {
        if (
            navigation.classList.contains("open") &&
            !navigation.contains(event.target) &&
            !menuToggle.contains(event.target)
        ) {
            closeMenu();
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeMenu();
        }
    });

    window.addEventListener("resize", function () {
        if (window.innerWidth > 650) {
            closeMenu();
        }
    });

    // REVEAL SECTIONS AS THE USER SCROLLS
    const revealItems = document.querySelectorAll(
        ".benefit, .category-card, .section-heading, .order-banner-content"
    );

    const reducedMotion = window.matchMedia(
        "(prefers-reduced-motion: reduce)"
    ).matches;

    if ("IntersectionObserver" in window && !reducedMotion) {
        revealItems.forEach(function (item) {
            item.classList.add("reveal");
        });

        const observer = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add("visible");
                    obs.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.12
        });

        revealItems.forEach(function (item) {
            observer.observe(item);
        });
    }

});
</script>

</body>
</html>


