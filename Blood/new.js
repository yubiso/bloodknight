const registerButton = document.getElementById("register");
const loginButton = document.getElementById("login");
const container = document.getElementById("container");

registerButton.addEventListener("click", () => {
    container.classList.add("right-panel-active");
});

loginButton.addEventListener("click", () => {
    container.classList.remove("right-panel-active");
});

// Password toggle functionality
const passwordToggles = document.querySelectorAll('.password-toggle');

passwordToggles.forEach(toggle => {
    toggle.addEventListener('click', function() {
        const targetId = this.getAttribute('data-target');
        const passwordInput = document.getElementById(targetId);
        
        // Toggle password visibility
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            // Visual feedback for "eye closed" - hiding password
            this.style.opacity = '0.5';
            this.style.transform = 'translateY(-50%) rotate(15deg) scale(0.8)';
            this.style.filter = 'grayscale(100%)';
        } else {
            passwordInput.type = 'password';
            // Visual feedback for "eye open" - showing password
            this.style.opacity = '1';
            this.style.transform = 'translateY(-50%) rotate(0deg) scale(1)';
            this.style.filter = 'grayscale(0%)';
        }
        
        console.log('Password type:', passwordInput.type);
    });
});
