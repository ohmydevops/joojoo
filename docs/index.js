console.log('Joojoo');

const btn = document.getElementById('testBtn');
const result = document.getElementById('result');

if (btn && result) {
    btn.addEventListener('click', async () => {
        btn.disabled = true;
        result.textContent = 'Testing...';
        
        try {
            const start = performance.now();
            const response = await fetch(window.location.href);
            const time = Math.round(performance.now() - start);
            
            if (response.ok) {
                result.textContent = `${response.status} OK • ${time}ms`;
            } else {
                result.textContent = `Error: ${response.status}`;
            }
        } catch (error) {
            result.textContent = `Error: ${error.message}`;
        } finally {
            btn.disabled = false;
        }
    });
}