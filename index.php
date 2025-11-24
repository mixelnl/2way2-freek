<?php
session_start();

// Check login state
$hasLogin = isset($_SESSION['login_success']) && $_SESSION['login_success'] === true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Freek AI-assistent</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        .typing-dot { animation: typing 1.4s infinite ease-in-out both; }
        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }
        @keyframes typing { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }
        pre { white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body class="bg-white h-screen flex flex-col text-gray-800 antialiased selection:bg-blue-100">

    <!-- Header -->
    <header class="flex-none px-6 py-4 flex justify-between items-center border-b border-gray-100 bg-white/80 backdrop-blur z-10 sticky top-0">
        <div class="flex items-center gap-2">
            <span class="text-xl"><img src="https://fleet.nl/logo.png" alt="Fleet" class=" h-4"></span>
            <h1 class="font-medium text-lg text-gray-700 tracking-tight">Freek</h1>
        </div>
        <?php if ($hasLogin): ?>
            <button id="resetBtn" class="text-xs text-gray-400 hover:text-red-500 transition-colors uppercase tracking-wider font-semibold">
                Reset Session
            </button>
        <?php endif; ?>
    </header>

    <!-- Chat Area -->
    <main id="chatContainer" class="flex-1 overflow-y-auto p-4 pb-32 scroll-smooth">
        <div class="max-w-3xl mx-auto flex flex-col gap-6" id="messages">
            <!-- Messages will be injected here -->
        </div>
        
        <!-- Typing Indicator -->
        <div id="typingIndicator" class="hidden max-w-3xl mx-auto mt-4">
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-blue-500 to-purple-500 flex items-center justify-center text-white text-xs shadow-sm flex-shrink-0">
                    AI
                </div>
                <div class="bg-gray-100 rounded-2xl rounded-tl-none px-4 py-3 shadow-sm">
                    <div class="flex gap-1">
                        <div class="w-2 h-2 bg-gray-400 rounded-full typing-dot"></div>
                        <div class="w-2 h-2 bg-gray-400 rounded-full typing-dot"></div>
                        <div class="w-2 h-2 bg-gray-400 rounded-full typing-dot"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Input Area -->
    <footer class="flex-none p-4 bg-white">
        <div class="max-w-3xl mx-auto relative">
            <div class="relative flex items-end gap-2 bg-gray-50 rounded-3xl border border-gray-200 shadow-sm hover:shadow-md focus-within:shadow-md focus-within:border-gray-300 transition-all duration-200 p-2">
                <textarea 
                    id="userInput"
                    rows="1"
                    class="w-full bg-transparent border-none focus:ring-0 resize-none py-3 px-4 max-h-32 text-gray-700 placeholder-gray-400 leading-relaxed"
                    placeholder="Stuur een bericht..."
                ></textarea>
                <button 
                    id="sendBtn"
                    class="p-2 bg-gray-900 text-white rounded-full hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors mb-1 mr-1"
                    disabled
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5">
                        <path d="M3.478 2.405a.75.75 0 00-.926.94l2.432 7.905H13.5a.75.75 0 010 1.5H4.984l-2.432 7.905a.75.75 0 00.926.94 60.519 60.519 0 0018.445-8.986.75.75 0 000-1.218A60.517 60.517 0 003.478 2.405z" />
                    </svg>
                </button>
            </div>
            <p class="text-center text-xs text-gray-400 mt-3">
                AI kan fouten maken. Controleer belangrijke informatie.
            </p>
        </div>
    </footer>

    <script>
        const state = {
            loggedIn: <?= json_encode($hasLogin) ?>,
            processing: false,
            history: []
        };

        const elements = {
            messages: document.getElementById('messages'),
            input: document.getElementById('userInput'),
            sendBtn: document.getElementById('sendBtn'),
            typing: document.getElementById('typingIndicator'),
            chatContainer: document.getElementById('chatContainer'),
            resetBtn: document.getElementById('resetBtn')
        };

        // Auto-resize textarea
        elements.input.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
            elements.sendBtn.disabled = this.value.trim() === '';
        });

        // Handle Enter key
        elements.input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (!elements.sendBtn.disabled) sendMessage();
            }
        });

        elements.sendBtn.addEventListener('click', sendMessage);

        if (elements.resetBtn) {
            elements.resetBtn.addEventListener('click', async () => {
                if (confirm('Sessie resetten?')) {
                    await fetch('api/reset.php', { method: 'POST' });
                    location.reload();
                }
            });
        }

        function addMessage(text, isUser = false, isHtml = false) {
            const div = document.createElement('div');
            div.className = `flex items-start gap-3 ${isUser ? 'flex-row-reverse' : ''} animate-fade-in`;
            
            const avatar = isUser 
                ? `<div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-gray-500 text-xs flex-shrink-0">U</div>`
                : `<div class="w-8 h-8 rounded-full bg-gradient-to-tr from-blue-500 to-purple-500 flex items-center justify-center text-white text-xs shadow-sm flex-shrink-0">AI</div>`;
            
            const bubble = isUser
                ? `<div class="bg-gray-100 text-gray-800 rounded-2xl rounded-tr-none px-5 py-3 max-w-[85%] shadow-sm">${escapeHtml(text)}</div>`
                : `<div class="bg-white border border-gray-100 text-gray-800 rounded-2xl rounded-tl-none px-5 py-3 max-w-[85%] shadow-sm prose prose-sm max-w-none">${isHtml ? text : formatText(text)}</div>`;

            div.innerHTML = avatar + bubble;
            elements.messages.appendChild(div);
            scrollToBottom();
        }

        function scrollToBottom() {
            elements.chatContainer.scrollTop = elements.chatContainer.scrollHeight;
        }

        function showTyping(show) {
            elements.typing.classList.toggle('hidden', !show);
            if (show) scrollToBottom();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatText(text) {
            // Simple formatting: convert URLs to links, newlines to breaks, bold to strong
            let formatted = escapeHtml(text);
            formatted = formatted.replace(/^(#{1,3})\s+(.*)$/gm, '<strong>$2</strong>');
            formatted = formatted.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            formatted = formatted.replace(/\n/g, '<br>');
            // Basic code block detection (for fetched content)
            if (text.includes('```') || text.length > 500) {
                return `<pre class="bg-gray-50 p-3 rounded-lg text-xs overflow-x-auto border border-gray-100 font-mono text-gray-600">${formatted}</pre>`;
            }
            return formatted;
        }

        async function sendMessage() {
            const text = elements.input.value.trim();
            if (!text || state.processing) return;

            // Add user message
            addMessage(text, true);
            elements.input.value = '';
            elements.input.style.height = 'auto';
            elements.sendBtn.disabled = true;
            state.processing = true;
            showTyping(true);

            try {
                if (!state.loggedIn) {
                    // Handle Login
                    const formData = new FormData();
                    formData.append('loginUrl', text);
                    
                    const response = await fetch('api/login.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    showTyping(false);

                    if (result.success) {
                        state.loggedIn = true;
                        addMessage(`✅ Login succesvol! ${result.message}.`);
                        addMessage("Stel je vraag over je leasecontract en voorwaarden.");
                        // Refresh page to update PHP session state in UI if needed (e.g. reset button)
                        // For now, we just update JS state.
                    } else {
                        addMessage(`❌ Login mislukt: ${result.error}`);
                        addMessage("Probeer het opnieuw met een geldige magic link.");
                    }
                } else {
                    // Handle Chat
                    const history = state.history || [];
                    
                    const response = await fetch('api/chat.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            message: text,
                            history: history
                        })
                    });
                    const result = await response.json();

                    showTyping(false);

                    if (result.success) {
                        // Parse markdown if possible, or just text. 
                        // The addMessage supports HTML if 3rd arg is true.
                        // Let's format simple markdown (bold, code) using a helper or just basic text.
                        // For now, just text.
                        addMessage(result.message, false);
                        
                        // Update history
                        state.history.push({ role: 'user', content: text });
                        state.history.push({ role: 'ai', content: result.message });
                    } else {
                        if (result.redirected_to_login) {
                            state.loggedIn = false;
                            addMessage("⚠️ Sessie verlopen. Stuur een nieuwe magic login link.");
                        } else {
                            addMessage(`❌ Fout: ${result.error}`);
                        }
                    }
                }
            } catch (e) {
                showTyping(false);
                addMessage(`❌ Er is een systeemfout opgetreden: ${e.message}`);
            } finally {
                state.processing = false;
                elements.input.focus();
            }
        }

        // Initial Message
        function init() {
            if (!state.loggedIn) {
                addMessage("Stuur eerst de magic login link, daarna kan je het gesprek beginnen.");
            } else {
                addMessage("Welkom terug! Je bent ingelogd. Stel je vraag over je leasecontract en voorwaarden.");
            }
            elements.input.focus();
        }

        // Start
        init();

    </script>
</body>
</html>
