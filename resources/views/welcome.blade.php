<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Automated DTR Service | Daily Time Record Platform</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,600,800&display=swap" rel="stylesheet" />

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="font-sans antialiased text-gray-900 bg-gray-50 flex flex-col min-h-screen justify-between">

    <main class="max-w-4xl mx-auto px-6 py-16 sm:py-24">
        
        <div class="flex items-center gap-2 mb-12 justify-center sm:justify-start">
            <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold">⏱️</div>
            <span class="text-xl font-bold tracking-tight text-gray-900">DTR<span class="text-blue-600">service</span></span>
        </div>

        <div class="space-y-4 mb-16 text-center sm:text-left">
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900 leading-tight">
                Automated DTR Management
            </h1>
            <div class="flex flex-wrap gap-3 pt-2">
                <a href="/devices" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"/></svg>
                    Biometric Devices
                </a>
                <a href="/logs/alert" class="inline-flex items-center gap-2 bg-orange-600 hover:bg-orange-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    Device Log Alert
                </a>
                <a href="/logs" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Log Viewer
                </a>
            </div>
        </div>

        <div class="space-y-8 mb-16">
            <h2 class="text-xl font-bold tracking-wide uppercase text-gray-400 border-b border-gray-200 pb-2">Service Capabilities</h2>
            
            <div class="grid sm:grid-cols-2 gap-8">
                <div>
                    <h3 class="font-bold text-gray-950 text-base mb-1">Real-Time Syncing</h3>
                    <p class="text-sm text-gray-600 leading-relaxed">Instantly records clock-ins via mobile apps, web browsers, or integrated biometric hardware layouts with zero reporting delays.</p>
                </div>
             
                <div>
                    <h3 class="font-bold text-gray-950 text-base mb-1">Automated Computations</h3>
                    <p class="text-sm text-gray-600 leading-relaxed">Handles calculations across custom shifts, complex night differentials, holiday premium policies, and late-arrival deductions natively.</p>
                </div>
              
            </div>
        </div>

        <div class="border-l-4 border-blue-600 bg-white p-6 rounded-r-xl shadow-xs">
            <p class="text-sm text-gray-700 italic">
           
            </p>
            <span class="block mt-3 text-xs font-semibold text-gray-900 tracking-wide">- ZCMC UMIS</span>
        </div>

    </main>

    <footer class="w-full bg-white border-t border-gray-200 py-6 text-center text-xs text-gray-400">
        <p>&copy; {{ date('Y') }} DTRservice Platform. Secure Attendance Infrastructure.</p>
    </footer>

</body>
</html>