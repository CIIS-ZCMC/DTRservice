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