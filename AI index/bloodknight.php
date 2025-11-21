<?php
/**
 * BLOODKNIGHT BACKEND LOGIC
 * Only runs on a server with PHP (e.g., XAMPP)
 */
session_start();

// Database Configuration
$servername = "localhost";
$username = "root";     // Default XAMPP username
$password = "";         // Default XAMPP password (empty)
$dbname = "bloodknight_db";

// Handle API Requests (JSON)
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    // Connect to DB
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die(json_encode(["status" => "error", "message" => "Database Connection Failed: " . $conn->connect_error]));
    }

    $action = $_GET['action'];

    // --- REGISTER ---
    if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $codename = $_POST['codename'];
        $email = $_POST['email'];
        $pass = password_hash($_POST['password'], PASSWORD_DEFAULT); // Secure hashing

        // Check if email exists
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(["status" => "error", "message" => "Email already registered."]);
        } else {
            // Insert User
            $stmt = $conn->prepare("INSERT INTO users (codename, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $codename, $email, $pass);
            if ($stmt->execute()) {
                $_SESSION['user_id'] = $conn->insert_id;
                $_SESSION['codename'] = $codename;
                echo json_encode(["status" => "success", "user" => ["name" => $codename]]);
            } else {
                echo json_encode(["status" => "error", "message" => "Registration failed."]);
            }
        }
    }

    // --- LOGIN ---
    elseif ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = $_POST['email'];
        $pass = $_POST['password'];

        $stmt = $conn->prepare("SELECT id, codename, password, rank_level FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (password_verify($pass, $row['password'])) {
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['codename'] = $row['codename'];
                echo json_encode(["status" => "success", "user" => ["name" => $row['codename'], "rank" => $row['rank_level']]]);
            } else {
                echo json_encode(["status" => "error", "message" => "Invalid password."]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "User not found."]);
        }
    }

    // --- CHECK SESSION ---
    elseif ($action === 'check_session') {
        if (isset($_SESSION['user_id'])) {
            echo json_encode(["status" => "logged_in", "user" => ["name" => $_SESSION['codename']]]);
        } else {
            echo json_encode(["status" => "guest"]);
        }
    }

    // --- REGISTER SQUAD ---
    elseif ($action === 'register_squad' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(["status" => "error", "message" => "Unauthorized"]);
            exit;
        }
        
        $name = $_POST['name'];
        $affiliation = $_POST['affiliation'];
        $size = $_POST['size'];
        $user_id = $_SESSION['user_id'];

        $stmt = $conn->prepare("INSERT INTO squads (name, affiliation, size, created_by) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssii", $name, $affiliation, $size, $user_id);
        
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Squad registered successfully."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Database error."]);
        }
    }

    // --- LOGOUT ---
    elseif ($action === 'logout') {
        session_destroy();
        echo json_encode(["status" => "success"]);
    }

    $conn->close();
    exit(); // Stop script here so HTML doesn't render for API calls
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BloodKnight | Defend the Pulse</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Rajdhani:wght@500;700&display=swap" rel="stylesheet">

    <!-- Custom CSS -->
    <style>
        :root { --primary-red: #dc2626; --dark-slate: #0f172a; }
        body { font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4, .tactical-font { font-family: 'Rajdhani', sans-serif; }
        @keyframes pulse-red { 0%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.4); } 50% { box-shadow: 0 0 0 15px rgba(220, 38, 38, 0); } }
        .animate-pulse-red { animation: pulse-red 2s infinite; }
        .glass-panel { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); border: 1px solid rgba(226, 232, 240, 0.8); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .image-card-hover { transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .image-card-hover:hover { transform: translateY(-5px) scale(1.01); box-shadow: 0 20px 25px -5px rgba(220, 38, 38, 0.15); }
        .hidden-section { display: none !important; }
        .fade-in { animation: fadeIn 0.4s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .modal-overlay { background-color: rgba(15, 23, 42, 0.85); backdrop-filter: blur(5px); }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::selection { background-color: #fee2e2; color: #7f1d1d; }
    </style>
</head>
<body class="bg-white font-sans text-slate-900 selection:bg-red-100 selection:text-red-900">

    <!-- Navigation Bar -->
    <nav class="sticky top-0 z-40 w-full bg-white/90 backdrop-blur-lg border-b border-slate-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-20 items-center">
                <div class="flex items-center cursor-pointer group" onclick="navigateTo('home')">
                    <div class="relative mr-3">
                        <i class="fas fa-shield-alt text-3xl text-slate-800 group-hover:text-slate-900 transition-colors"></i>
                        <i class="fas fa-tint text-red-600 absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 text-sm"></i>
                    </div>
                    <span class="text-2xl font-bold tactical-font text-slate-900 tracking-tighter uppercase group-hover:text-red-700 transition-colors">
                        Blood<span class="text-red-600">Knight</span>
                    </span>
                </div>

                <div class="hidden md:flex space-x-8 items-center">
                    <button onclick="navigateTo('home')" class="nav-link text-sm font-bold uppercase tracking-wide transition-colors text-red-700 hover:text-red-600" data-target="home">Headquarters</button>
                    <button onclick="navigateTo('eligibility')" class="nav-link text-sm font-bold uppercase tracking-wide transition-colors text-slate-500 hover:text-red-600" data-target="eligibility">Check Status</button>
                    <button onclick="navigateTo('donate')" class="nav-link text-sm font-bold uppercase tracking-wide transition-colors text-slate-500 hover:text-red-600" data-target="donate">Missions</button>
                    <button onclick="navigateTo('supply')" class="nav-link text-sm font-bold uppercase tracking-wide transition-colors text-slate-500 hover:text-red-600" data-target="supply">Supply</button>
                </div>

                <div class="hidden md:flex items-center space-x-4" id="auth-container-desktop">
                    <button onclick="openAuthModal()" id="btn-join-desktop" class="bg-red-700 text-white hover:bg-red-800 shadow-lg shadow-red-200 border border-red-700 px-6 py-2.5 rounded-lg font-bold uppercase tracking-wider text-sm transition-all transform hover:-translate-y-0.5 flex items-center">
                        <i class="fas fa-sign-in-alt mr-2"></i> Login
                    </button>
                    <div id="profile-desktop" class="hidden flex items-center space-x-3 cursor-pointer group" onclick="navigateTo('recruitment')">
                        <div class="text-right hidden lg:block">
                            <p class="text-xs text-slate-400 uppercase font-bold tracking-wider">Commander</p>
                            <p class="text-sm font-black text-slate-900 group-hover:text-red-600 transition-colors" id="username-display-desktop">User</p>
                        </div>
                        <div class="h-10 w-10 rounded-full bg-slate-200 border-2 border-white shadow-md overflow-hidden relative">
                             <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=Felix" alt="Avatar" class="object-cover w-full h-full">
                        </div>
                        <button onclick="handleLogout()" class="text-xs text-red-600 hover:text-red-800 font-bold ml-2 uppercase">Logout</button>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- === AUTH MODAL === -->
    <div id="auth-modal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 modal-overlay transition-opacity" onclick="closeAuthModal()"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-md p-4">
            <div class="bg-white rounded-2xl shadow-2xl overflow-hidden animate-fade-in">
                <div class="flex">
                    <button onclick="switchAuthTab('login')" id="tab-login" class="flex-1 py-4 text-sm font-bold uppercase tracking-wider bg-white text-red-700 border-b-2 border-red-700">Login</button>
                    <button onclick="switchAuthTab('register')" id="tab-register" class="flex-1 py-4 text-sm font-bold uppercase tracking-wider bg-slate-50 text-slate-400 border-b border-slate-200 hover:text-slate-600">Register</button>
                </div>
                
                <div class="p-8">
                    <div class="text-center mb-8">
                        <i class="fas fa-shield-alt text-4xl text-red-600 mb-2"></i>
                        <h3 class="text-2xl font-black tactical-font text-slate-900 uppercase" id="auth-title">Welcome Back</h3>
                        <p class="text-slate-500 text-sm">Enter your credentials to access the mainframe.</p>
                    </div>

                    <form onsubmit="handleAuth(event)" class="space-y-4">
                        <input type="hidden" id="auth-action" value="login">
                        <div id="register-fields" class="hidden space-y-4">
                            <div>
                                <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Codename</label>
                                <input type="text" id="auth-name" name="codename" class="w-full px-4 py-3 rounded-lg bg-slate-50 border border-slate-200 focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none font-medium" placeholder="e.g. Viper">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Email</label>
                            <input type="email" id="auth-email" name="email" required class="w-full px-4 py-3 rounded-lg bg-slate-50 border border-slate-200 focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none font-medium" placeholder="user@bloodknight.my">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Password</label>
                            <input type="password" id="auth-pass" name="password" required class="w-full px-4 py-3 rounded-lg bg-slate-50 border border-slate-200 focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none font-medium" placeholder="••••••••">
                        </div>
                        <button type="submit" class="w-full bg-red-700 text-white py-4 rounded-lg font-bold uppercase tracking-wider shadow-lg shadow-red-100 hover:bg-red-800 transform transition hover:-translate-y-0.5">
                            <span id="auth-btn-text">Identify</span> <i class="fas fa-arrow-right ml-2"></i>
                        </button>
                    </form>
                    
                    <div id="auth-error" class="text-center text-red-600 text-sm font-bold mt-4 hidden"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- === SQUAD MODAL === -->
    <div id="squad-modal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 modal-overlay transition-opacity" onclick="closeSquadModal()"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-md p-4">
            <div class="bg-white rounded-2xl shadow-2xl overflow-hidden animate-fade-in border border-slate-200">
                <div class="bg-slate-900 p-6 text-white relative overflow-hidden">
                    <div class="absolute top-0 right-0 p-4 opacity-10"><i class="fas fa-users text-6xl"></i></div>
                    <h3 class="text-2xl font-black tactical-font uppercase mb-1">Squadron Registry</h3>
                    <p class="text-slate-400 text-sm">Establish a new division for collective operations.</p>
                </div>
                
                <div class="p-8">
                    <form onsubmit="handleSquadRegister(event)" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Squadron Name</label>
                            <input type="text" id="squad-name" required class="w-full px-4 py-3 rounded-lg bg-slate-50 border border-slate-200 focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none font-medium">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Affiliation</label>
                            <select id="squad-affiliation" class="w-full px-4 py-3 rounded-lg bg-slate-50 border border-slate-200 focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none font-medium text-slate-700">
                                <option>Corporate Entity</option>
                                <option>University / College</option>
                                <option>NGO / Non-Profit</option>
                                <option>Community Group</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Est. Strength</label>
                            <input type="number" id="squad-size" min="5" placeholder="5+" class="w-full px-4 py-3 rounded-lg bg-slate-50 border border-slate-200 focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none font-medium">
                        </div>
                        <button type="submit" class="w-full bg-red-700 text-white py-4 rounded-lg font-bold uppercase tracking-wider shadow-lg shadow-red-100 hover:bg-red-800 transform transition hover:-translate-y-0.5">
                            Initialize Squad
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- === SECTIONS (Content) === -->

    <!-- HOME SECTION -->
    <div id="home" class="page-section fade-in">
        <main>
            <section class="relative overflow-hidden pt-20 pb-20 lg:pt-32 lg:pb-32">
                <div class="absolute inset-0 z-0">
                    <img src="https://images.unsplash.com/photo-1615461066159-fea0960485d5?auto=format&fit=crop&q=80&w=2000" class="w-full h-full object-cover opacity-40">
                    <div class="absolute inset-0 bg-gradient-to-r from-white via-white/80 to-white/30"></div>
                </div>
                
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
                    <div class="lg:grid lg:grid-cols-12 lg:gap-16 items-center">
                        <div class="lg:col-span-7 text-center lg:text-left z-10">
                            <div class="inline-flex items-center px-4 py-2 rounded border border-red-200 bg-white/80 backdrop-blur-sm text-red-700 text-xs font-bold uppercase tracking-widest mb-8 animate-pulse shadow-sm">
                                <i class="fas fa-heartbeat mr-2"></i> Critical: O- Reserves Depleted
                            </div>
                            <h1 class="text-5xl sm:text-6xl lg:text-7xl font-black text-slate-900 tracking-tighter mb-8 leading-none drop-shadow-sm">
                                DEFEND THE <br/>
                                <span class="text-red-600">PULSE.</span>
                            </h1>
                            <p class="text-xl text-slate-800 font-medium mb-10 leading-relaxed max-w-2xl mx-auto lg:mx-0">
                                Malaysia needs heroes. Your donation shields the vulnerable in our community. Join the BloodKnights of Malaysia today.
                            </p>
                            <div class="flex flex-col sm:flex-row gap-5 justify-center lg:justify-start">
                                <button onclick="navigateTo('donate')" class="animate-pulse-red bg-red-700 text-white hover:bg-red-800 shadow-xl shadow-red-200 px-6 py-3 rounded-lg font-bold uppercase tracking-wider text-sm transition-all transform hover:-translate-y-0.5">
                                    <i class="fas fa-khanda mr-2"></i> Accept Mission
                                </button>
                                <button onclick="navigateTo('eligibility')" class="bg-white/80 backdrop-blur text-slate-600 border border-slate-300 hover:text-slate-900 hover:border-slate-400 px-6 py-3 rounded-lg font-bold uppercase tracking-wider text-sm transition-all">
                                    <i class="fas fa-shield-alt mr-2"></i> Verify Status
                                </button>
                            </div>
                            <div class="mt-16 grid grid-cols-3 gap-8 border-t border-slate-200/60 pt-8">
                                <div><p class="text-3xl font-black text-slate-900">50k+</p><p class="text-xs text-slate-600 uppercase tracking-widest mt-1 font-bold">Knights</p></div>
                                <div><p class="text-3xl font-black text-slate-900">120k+</p><p class="text-xs text-slate-600 uppercase tracking-widest mt-1 font-bold">Lives Saved</p></div>
                                <div><p class="text-3xl font-black text-slate-900">150+</p><p class="text-xs text-slate-600 uppercase tracking-widest mt-1 font-bold">Strongholds</p></div>
                            </div>
                        </div>
                        <div class="lg:col-span-5 mt-16 lg:mt-0 relative">
                            <div class="relative rounded-2xl bg-white border border-slate-200 p-3 shadow-2xl rotate-3 hover:rotate-0 transition-transform duration-500">
                                <div class="absolute -inset-1 bg-gradient-to-br from-red-600 to-red-400 rounded-2xl blur opacity-20"></div>
                                <div class="relative rounded-xl overflow-hidden aspect-[4/5] group">
                                    <img src="https://images.unsplash.com/photo-1615461066841-6116e61058f4?auto=format&fit=crop&q=80&w=1000" class="object-cover w-full h-full transition-transform duration-700 group-hover:scale-105">
                                    <div class="absolute bottom-0 left-0 right-0 p-6 bg-gradient-to-t from-slate-900/90 to-transparent text-white">
                                        <p class="font-bold text-lg">Guardian Amirul</p>
                                        <p class="text-xs text-slate-300 uppercase tracking-widest">Donated at PDN 5 mins ago</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Vanguard Gallery -->
            <section class="py-24 bg-white relative border-t border-slate-100">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="text-center mb-16"><h2 class="text-4xl font-black text-slate-900 uppercase tracking-tight mb-4">The Vanguard</h2><p class="text-slate-500 max-w-2xl mx-auto">Real heroes don't wear capes. Select a division below.</p></div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                        <div onclick="navigateTo('community')" class="group relative rounded-2xl overflow-hidden shadow-lg aspect-[4/3] image-card-hover cursor-pointer"><img src="https://images.unsplash.com/photo-1559027615-cd4628902d4a?auto=format&fit=crop&q=80&w=800" class="object-cover w-full h-full group-hover:scale-110 transition-transform duration-700"><div class="absolute inset-0 bg-slate-900/40 group-hover:bg-slate-900/20 transition-colors"></div><div class="absolute bottom-0 left-0 p-6 w-full"><span class="px-3 py-1 bg-red-600 text-white text-xs font-bold uppercase tracking-widest rounded mb-3 inline-block">Squadron Alpha</span><h3 class="text-white font-bold text-2xl uppercase mb-1">Community Drive</h3></div></div>
                        <div onclick="navigateTo('care')" class="group relative rounded-2xl overflow-hidden shadow-lg aspect-[4/3] image-card-hover cursor-pointer"><img src="https://images.unsplash.com/photo-1579154204601-01588f351e67?auto=format&fit=crop&q=80&w=800" class="object-cover w-full h-full group-hover:scale-110 transition-transform duration-700"><div class="absolute inset-0 bg-slate-900/40 group-hover:bg-slate-900/20 transition-colors"></div><div class="absolute bottom-0 left-0 p-6 w-full"><span class="px-3 py-1 bg-blue-600 text-white text-xs font-bold uppercase tracking-widest rounded mb-3 inline-block">Support Unit</span><h3 class="text-white font-bold text-2xl uppercase mb-1">Care & Comfort</h3></div></div>
                        <div onclick="navigateTo('recruitment')" class="group relative rounded-2xl overflow-hidden shadow-lg aspect-[4/3] image-card-hover cursor-pointer md:col-span-2 lg:col-span-1"><img src="https://images.unsplash.com/photo-1544027993-37dbfe43562a?auto=format&fit=crop&q=80&w=800" class="object-cover w-full h-full group-hover:scale-110 transition-transform duration-700"><div class="absolute inset-0 bg-slate-900/40 group-hover:bg-slate-900/20 transition-colors"></div><div class="absolute bottom-0 left-0 p-6 w-full"><span class="px-3 py-1 bg-green-600 text-white text-xs font-bold uppercase tracking-widest rounded mb-3 inline-block">Rank Up</span><h3 class="text-white font-bold text-2xl uppercase mb-1">New Hope</h3></div></div>
                    </div>
                </div>
            </section>

             <!-- Locate A Drive Section -->
            <section class="py-24 bg-white text-slate-900 relative overflow-hidden border-t border-slate-100">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
                    <div class="lg:grid lg:grid-cols-2 gap-16 items-center">
                        <div>
                            <h2 class="text-4xl font-black tactical-font uppercase tracking-tight mb-6">Locate A Drive</h2>
                            <p class="text-slate-500 text-lg mb-8">Mobile units are active across the Klang Valley and beyond. Find a deployment sector near you.</p>
                            <div class="bg-slate-50 p-2 rounded-xl border border-slate-200 flex flex-col sm:flex-row gap-2 mb-8 shadow-sm">
                                <input type="text" placeholder="Enter Postcode" class="flex-1 bg-transparent border-none text-slate-900 px-4 py-3 outline-none w-full font-medium">
                                <button onclick="navigateTo('donate')" class="bg-red-700 text-white hover:bg-red-800 px-6 py-3 rounded-lg font-bold uppercase text-sm">Scan Sector</button>
                            </div>
                        </div>
                        <div class="hidden lg:block relative h-full min-h-[500px] rounded-2xl bg-slate-100 border border-slate-200">
                             <div class="absolute top-1/4 left-1/4 animate-pulse"><div class="w-4 h-4 bg-red-600 rounded-full"></div></div>
                             <div class="absolute bottom-6 left-6 bg-white/90 px-4 py-3 rounded border border-slate-200"><p class="text-xs text-slate-500 uppercase">Klang Valley</p><p class="text-red-600 font-bold">3 Units Active</p></div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- COMMUNITY SECTION -->
    <div id="community" class="page-section hidden-section fade-in">
        <div class="max-w-7xl mx-auto px-4 py-20">
            <div class="text-center mb-12"><h2 class="text-4xl font-black tactical-font text-slate-900 uppercase mt-2">Top Battalions</h2></div>
            <div class="grid md:grid-cols-3 gap-8 mb-16">
                <div class="bg-white p-6 rounded-2xl shadow-lg border-t-4 border-yellow-500"><h3 class="font-bold text-lg uppercase">Red Crescent Elite</h3><p class="font-black text-2xl text-slate-900">4,250L</p></div>
                <div class="bg-white p-6 rounded-2xl shadow-lg border-t-4 border-slate-400"><h3 class="font-bold text-lg uppercase">UniMalaya Heroes</h3><p class="font-black text-2xl text-slate-900">3,890L</p></div>
                <div class="bg-white p-6 rounded-2xl shadow-lg border-t-4 border-orange-700"><h3 class="font-bold text-lg uppercase">Cyberjaya Techs</h3><p class="font-black text-2xl text-slate-900">2,100L</p></div>
            </div>
            <div class="bg-slate-900 rounded-3xl p-8 md:p-12 text-white flex flex-col md:flex-row items-center justify-between gap-8">
                <div><h3 class="text-2xl font-bold uppercase mb-2">Host a Drive</h3><p class="text-slate-400 max-w-md">Does your organization have what it takes?</p></div>
                <button onclick="openSquadModal()" class="bg-white text-slate-900 hover:bg-red-500 hover:text-white px-8 py-4 rounded-lg font-bold uppercase tracking-wider transition-all">Register Squad</button>
            </div>
        </div>
    </div>

    <!-- CARE SECTION -->
    <div id="care" class="page-section hidden-section fade-in">
        <div class="max-w-7xl mx-auto px-4 py-20">
            <h2 class="text-4xl font-black tactical-font text-slate-900 uppercase mb-6">Support Unit</h2>
            <p class="text-slate-600 mb-8">Join the support unit to manage logistics, comfort donors, and run the events.</p>
            <div class="grid sm:grid-cols-2 gap-6">
                <div class="bg-white border border-slate-200 p-6 rounded-xl"><h3 class="font-bold text-lg mb-1">Registration Officer</h3><p class="text-slate-600 text-sm">Manage donor intake forms.</p></div>
                <div class="bg-white border border-slate-200 p-6 rounded-xl"><h3 class="font-bold text-lg mb-1">Recovery Aide</h3><p class="text-slate-600 text-sm">Monitor donors in resting area.</p></div>
            </div>
        </div>
    </div>

    <!-- RECRUITMENT / PROFILE SECTION -->
    <div id="recruitment" class="page-section hidden-section fade-in">
        <div class="bg-slate-900 text-white pb-24 pt-20">
             <div class="max-w-7xl mx-auto px-4 text-center">
                 <div class="w-24 h-24 bg-slate-800 rounded-full mx-auto mb-6 border-4 border-red-600 flex items-center justify-center relative">
                     <i class="fas fa-user-astronaut text-4xl"></i>
                     <div class="absolute -bottom-2 bg-red-600 text-white text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wider" id="profile-rank">Recruit</div>
                 </div>
                 <h2 class="text-3xl font-black tactical-font uppercase mb-2" id="profile-name">Guest</h2>
             </div>
        </div>
        <div class="max-w-5xl mx-auto px-4 -mt-12 relative z-10">
            <div class="bg-white rounded-2xl shadow-xl border border-slate-200 p-8 mb-8">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-8 text-center">
                    <div><p class="text-4xl font-black text-slate-900">0</p><p class="text-xs text-slate-500 uppercase font-bold tracking-widest mt-2">Donations</p></div>
                    <div><p class="text-4xl font-black text-slate-900">0</p><p class="text-xs text-slate-500 uppercase font-bold tracking-widest mt-2">Lives Saved</p></div>
                    <div><p class="text-4xl font-black text-slate-900">0</p><p class="text-xs text-slate-500 uppercase font-bold tracking-widest mt-2">XP Gained</p></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ELIGIBILITY & SUPPLY (Remaining Sections) -->
    <div id="eligibility" class="page-section hidden-section fade-in"><div class="max-w-7xl mx-auto px-4 py-20"><div class="glass-panel rounded-2xl p-8 max-w-2xl mx-auto" id="quiz-container"></div></div></div>
    <div id="supply" class="page-section hidden-section fade-in"><div class="max-w-7xl mx-auto px-4 py-20"><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6" id="supply-grid"></div></div></div>
    <div id="donate" class="page-section hidden-section fade-in">
        <div class="max-w-7xl mx-auto px-4 py-12 sm:py-20"><div class="lg:w-2/3"><div class="grid gap-4" id="locations-grid"></div></div></div>
    </div>

    <!-- Footer -->
    <footer class="bg-slate-900 text-slate-400 py-16 border-t border-slate-800"><div class="max-w-7xl mx-auto px-4 text-center"><p>BloodKnight &copy; 2025</p></div></footer>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-4 right-4 z-50 px-6 py-4 rounded-lg shadow-xl flex items-center border backdrop-blur-md transition-all duration-300 transform translate-y-20 opacity-0">
        <i id="toast-icon" class="fas fa-check-circle mr-3"></i>
        <span id="toast-message" class="font-bold tracking-wide text-sm"></span>
    </div>

    <script>
        // --- HYBRID FETCH (DETECTS PHP) ---
        let useSimulation = false;

        async function phpFetch(action, formData = null) {
            if (useSimulation) return { status: 'simulation' };

            try {
                const options = { method: formData ? 'POST' : 'GET' };
                if (formData) options.body = formData;

                // Call the PHP file (itself)
                const response = await fetch(`index.php?action=${action}`, options);
                
                // Check if response is valid JSON
                const text = await response.text();
                try {
                    const data = JSON.parse(text);
                    return data; 
                } catch (e) {
                    // If parsing fails, PHP probably isn't running (returned HTML source)
                    console.warn("PHP Not Detected. Switching to Simulation Mode.");
                    useSimulation = true;
                    return { status: 'simulation' };
                }
            } catch (e) {
                console.warn("Network Error. Switching to Simulation Mode.");
                useSimulation = true;
                return { status: 'simulation' };
            }
        }

        // --- STATE ---
        let isLoggedIn = false;
        let userName = "Guest";
        let userRank = "Recruit";

        // --- APP LOGIC ---
        document.addEventListener('DOMContentLoaded', async () => {
            // Check Session on Load
            const res = await phpFetch('check_session');
            if (res.status === 'logged_in') {
                isLoggedIn = true;
                userName = res.user.name;
                updateAuthUI();
            }
            
            renderQuiz();
            renderLocations();
            renderSupply();
        });

        function navigateTo(pageId) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            document.querySelectorAll('.page-section').forEach(el => el.classList.add('hidden-section'));
            const target = document.getElementById(pageId);
            if(target) target.classList.remove('hidden-section');
            document.querySelectorAll('.nav-link').forEach(el => {
                if(el.dataset.target === pageId) { el.classList.remove('text-slate-500'); el.classList.add('text-red-700'); } 
                else { el.classList.add('text-slate-500'); el.classList.remove('text-red-700'); }
            });
        }

        function toggleMobileMenu() { document.getElementById('mobile-menu').classList.toggle('hidden'); }
        function openAuthModal() { document.getElementById('auth-modal').classList.remove('hidden'); }
        function closeAuthModal() { document.getElementById('auth-modal').classList.add('hidden'); }

        function switchAuthTab(tab) {
            const loginBtn = document.getElementById('tab-login');
            const regBtn = document.getElementById('tab-register');
            const regFields = document.getElementById('register-fields');
            const title = document.getElementById('auth-title');
            const btnText = document.getElementById('auth-btn-text');
            const hiddenAction = document.getElementById('auth-action');

            if(tab === 'register') {
                loginBtn.classList.remove('text-red-700', 'border-red-700', 'bg-white'); loginBtn.classList.add('bg-slate-50', 'text-slate-400', 'border-slate-200');
                regBtn.classList.add('text-red-700', 'border-red-700', 'bg-white'); regBtn.classList.remove('bg-slate-50', 'text-slate-400', 'border-slate-200');
                regFields.classList.remove('hidden');
                title.textContent = 'New Recruit';
                btnText.textContent = 'Register';
                hiddenAction.value = 'register';
            } else {
                regBtn.classList.remove('text-red-700', 'border-red-700', 'bg-white'); regBtn.classList.add('bg-slate-50', 'text-slate-400', 'border-slate-200');
                loginBtn.classList.add('text-red-700', 'border-red-700', 'bg-white'); loginBtn.classList.remove('bg-slate-50', 'text-slate-400', 'border-slate-200');
                regFields.classList.add('hidden');
                title.textContent = 'Welcome Back';
                btnText.textContent = 'Identify';
                hiddenAction.value = 'login';
            }
        }

        async function handleAuth(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            const action = document.getElementById('auth-action').value; // 'login' or 'register'
            
            const res = await phpFetch(action, formData);

            if (res.status === 'success') {
                isLoggedIn = true;
                userName = res.user.name;
                if(res.user.rank) userRank = res.user.rank;
                updateAuthUI();
                closeAuthModal();
                showNotification(`Welcome, ${userName}. Systems Online.`);
            } else if (res.status === 'simulation') {
                // FALLBACK FOR PREVIEW MODE
                isLoggedIn = true;
                userName = document.getElementById('auth-name').value || "Commander";
                updateAuthUI();
                closeAuthModal();
                showNotification(`[SIMULATION] Welcome, ${userName}.`);
            } else {
                document.getElementById('auth-error').textContent = res.message;
                document.getElementById('auth-error').classList.remove('hidden');
            }
        }

        async function handleLogout() {
            await phpFetch('logout');
            isLoggedIn = false;
            userName = "Guest";
            window.location.reload(); // Refresh to clear state
        }

        function updateAuthUI() {
            if(isLoggedIn) {
                document.getElementById('btn-join-desktop').classList.add('hidden');
                document.getElementById('profile-desktop').classList.remove('hidden');
                document.getElementById('profile-desktop').classList.add('flex');
                document.getElementById('username-display-desktop').textContent = userName;
                document.getElementById('profile-name').textContent = userName;
                document.getElementById('profile-rank').textContent = userRank;
            }
        }

        // --- SQUAD SYSTEM ---
        function openSquadModal() {
            if (!isLoggedIn) { showNotification("Authentication Required.", "error"); openAuthModal(); return; }
            document.getElementById('squad-modal').classList.remove('hidden');
        }
        function closeSquadModal() { document.getElementById('squad-modal').classList.add('hidden'); }

        async function handleSquadRegister(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            
            const res = await phpFetch('register_squad', formData);

            if (res.status === 'success') {
                closeSquadModal();
                showNotification("Squadron registered successfully.", "success");
            } else if (res.status === 'simulation') {
                closeSquadModal();
                showNotification("[SIMULATION] Squad registered.", "success");
            } else {
                alert(res.message);
            }
        }

        // --- UTILS ---
        function showNotification(message, type = 'success') {
            const toast = document.getElementById('toast');
            document.getElementById('toast-message').textContent = message;
            if(type === 'success') {
                toast.className = "fixed bottom-4 right-4 z-50 px-6 py-4 rounded-lg shadow-xl flex items-center border backdrop-blur-md bg-green-50 text-green-800 border-green-200 transition-all duration-300";
                document.getElementById('toast-icon').className = "fas fa-check-circle mr-3 text-green-600";
            } else {
                toast.className = "fixed bottom-4 right-4 z-50 px-6 py-4 rounded-lg shadow-xl flex items-center border backdrop-blur-md bg-red-50 text-red-800 border-red-200 transition-all duration-300";
                document.getElementById('toast-icon').className = "fas fa-times-circle mr-3 text-red-600";
            }
            toast.classList.remove('translate-y-20', 'opacity-0');
            setTimeout(() => { toast.classList.add('translate-y-20', 'opacity-0'); }, 3000);
        }

        function attemptBooking(locationName) {
            if (!isLoggedIn) { showNotification("Authentication Required.", "error"); openAuthModal(); } 
            else { showNotification(`Mission confirmed at ${locationName}.`); }
        }

        // --- DATA RENDERING (Simplified for brevity) ---
        const LOCATIONS = [
            { id: 1, name: 'Pusat Darah Negara', address: 'Jalan Tun Razak, Kuala Lumpur', distance: '0.8 km', nextSlot: 'Today, 14:00' },
            { id: 2, name: 'Mid Valley Donation Suite', address: 'Lingkaran Syed Putra, KL', distance: '5.4 km', nextSlot: 'Tomorrow, 10:00' },
        ];
        function renderLocations() {
            document.getElementById('locations-grid').innerHTML = LOCATIONS.map(loc => `
                <div class="bg-white p-6 rounded-xl border border-slate-200 hover:border-red-300 hover:shadow-md transition-all group shadow-sm cursor-pointer" onclick="attemptBooking('${loc.name}')">
                    <h4 class="text-xl font-bold tactical-font text-slate-900 group-hover:text-red-700 uppercase">${loc.name}</h4>
                    <p class="text-sm text-slate-500 mt-1"><i class="fas fa-map-marker-alt w-4 h-4 mr-1 text-red-600"></i> ${loc.address}</p>
                    <div class="mt-4 pt-4 border-t border-slate-100 flex justify-between items-center">
                        <span class="text-green-700 text-sm font-bold"><i class="fas fa-clock mr-1"></i> ${loc.nextSlot}</span>
                        <button class="text-red-600 font-bold text-sm uppercase">Book Slot</button>
                    </div>
                </div>`).join('');
        }

        const BLOOD_LEVELS = [{t:'A+',v:80},{t:'A-',v:30},{t:'B+',v:65},{t:'B-',v:20},{t:'O+',v:90},{t:'O-',v:15},{t:'AB+',v:70},{t:'AB-',v:40}];
        function renderSupply() {
             document.getElementById('supply-grid').innerHTML = BLOOD_LEVELS.map(b => {
                 let color = b.v < 30 ? 'bg-red-600' : (b.v < 70 ? 'bg-yellow-500' : 'bg-green-600');
                 return `<div class="bg-white p-6 rounded-xl border border-slate-200 hover:shadow-lg transition-all"><div class="flex justify-between items-end mb-2"><span class="text-4xl font-black tactical-font">${b.t}</span><span class="text-xs font-bold">${b.v}%</span></div><div class="w-full bg-slate-200 rounded-full h-3"><div class="${color} h-full rounded-full" style="width: ${b.v}%"></div></div></div>`;
             }).join('');
        }

        const QUESTIONS = [{t:"Are you at least 17 years of age?",r:true},{t:"Weight above 45kg?",r:true},{t:"Feeling healthy today?",r:true},{t:"Donated recently?",r:false}];
        let quizStep = 0;
        function renderQuiz() {
            const c = document.getElementById('quiz-container');
            if(quizStep >= QUESTIONS.length) { c.innerHTML = `<div class="text-center py-8"><i class="fas fa-check text-4xl text-green-600 mb-4"></i><h3 class="text-2xl font-black uppercase">Eligible</h3><button onclick="navigateTo('donate')" class="mt-6 bg-red-700 text-white px-8 py-3 rounded-lg font-bold uppercase">Find Station</button></div>`; return; }
            if(quizStep === -1) { c.innerHTML = `<div class="text-center py-8"><i class="fas fa-times text-4xl text-red-600 mb-4"></i><h3 class="text-2xl font-black uppercase">Deferred</h3><button onclick="quizStep=0;renderQuiz()" class="mt-6 text-slate-500 underline">Retry</button></div>`; return; }
            c.innerHTML = `<h3 class="text-2xl font-bold text-center mb-8 min-h-[4rem] flex items-center justify-center">${QUESTIONS[quizStep].t}</h3><div class="flex gap-4"><button onclick="handleAnswer(false)" class="flex-1 py-3 border border-slate-200 rounded-lg font-bold uppercase">No</button><button onclick="handleAnswer(true)" class="flex-1 py-3 bg-red-700 text-white rounded-lg font-bold uppercase">Yes</button></div>`;
        }
        function handleAnswer(a) { if((QUESTIONS[quizStep].r && !a) || (!QUESTIONS[quizStep].r && a)) quizStep=-1; else quizStep++; renderQuiz(); }
    </script>
</body>
</html>