/* CoreBB Modern 4: procedural full-frame canvas background. */
(function () {
    'use strict';

    function bootModern4Canvas() {
        var body = document.body;
        if (!body || !body.classList.contains('wb-modern-4')) {
            return;
        }

        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext && canvas.getContext('2d', { alpha: false });
        if (!ctx) {
            return;
        }

        canvas.className = 'corebb-modern4-canvas';
        canvas.setAttribute('aria-hidden', 'true');
        body.insertBefore(canvas, body.firstChild);

        var baseCanvas = document.createElement('canvas');
        var baseCtx = baseCanvas.getContext && baseCanvas.getContext('2d', { alpha: false });
        if (!baseCtx) {
            return;
        }

        var width = 0;
        var height = 0;
        var lastFrame = 0;
        var frameDelay = 1000 / 60;
        var motionSpeed = 1.15;
        var minBeamCount = 96;
        var maxBeamCount = 176;
        var sprites = [];
        var activeThemeName = '';
        var activeTheme = null;
        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function resize() {
            width = window.innerWidth || document.documentElement.clientWidth || 1;
            height = window.innerHeight || document.documentElement.clientHeight || 1;

            canvas.width = Math.max(1, Math.floor(width));
            canvas.height = Math.max(1, Math.floor(height));
            canvas.style.width = width + 'px';
            canvas.style.height = height + 'px';

            baseCanvas.width = canvas.width;
            baseCanvas.height = canvas.height;
            syncTheme(true);
            render(0);
        }

        function render(now) {
            if (activeThemeName !== currentThemeName()) {
                syncTheme(false);
            }
            var t = reduceMotion ? 0 : (now / 1000) * motionSpeed;
            ctx.drawImage(baseCanvas, 0, 0);
            drawSprites(t);
        }

        function currentThemeName() {
            return document.documentElement.classList.contains('corebb-dark-mode') ? 'dark' : 'light';
        }

        function themeConfig() {
            if (currentThemeName() === 'dark') {
                return {
                    name: 'dark',
                    base: ['#34205d', '#1b112d', '#08070d', '#08070d'],
                    wash: ['rgba(104, 213, 255, 0.08)', 'rgba(255, 106, 166, 0.03)', 'rgba(8, 7, 13, 0)'],
                    palette: [
                        '111, 71, 221',
                        '151, 112, 255',
                        '104, 213, 255',
                        '63, 214, 221',
                        '255, 106, 166',
                        '255, 158, 195',
                        '243, 210, 109',
                        '116, 255, 202'
                    ],
                    alphaMin: .1,
                    alphaRange: .2,
                    composite: 'lighter'
                };
            }

            return {
                name: 'light',
                base: ['#f7fbff', '#eef4fb', '#e8eff8', '#edf3fa'],
                wash: ['rgba(43, 125, 182, 0.12)', 'rgba(200, 86, 136, 0.06)', 'rgba(237, 243, 250, 0)'],
                palette: [
                    '43, 125, 182',
                    '106, 90, 162',
                    '36, 151, 165',
                    '31, 110, 157',
                    '200, 86, 136',
                    '154, 111, 33',
                    '88, 143, 196',
                    '74, 166, 151'
                ],
                alphaMin: .08,
                alphaRange: .16,
                composite: 'source-over'
            };
        }

        function syncTheme(force) {
            var nextTheme = themeConfig();
            if (!force && activeThemeName === nextTheme.name) {
                return;
            }

            activeTheme = nextTheme;
            activeThemeName = nextTheme.name;
            buildBase();
            buildSprites();
        }

        function buildBase() {
            var base = baseCtx.createLinearGradient(0, 0, 0, height);
            base.addColorStop(0, activeTheme.base[0]);
            base.addColorStop(.34, activeTheme.base[1]);
            base.addColorStop(.78, activeTheme.base[2]);
            base.addColorStop(1, activeTheme.base[3]);
            baseCtx.fillStyle = base;
            baseCtx.fillRect(0, 0, width, height);

            var wash = baseCtx.createLinearGradient(0, 0, width, height);
            wash.addColorStop(0, activeTheme.wash[0]);
            wash.addColorStop(.46, activeTheme.wash[1]);
            wash.addColorStop(1, activeTheme.wash[2]);
            baseCtx.fillStyle = wash;
            baseCtx.fillRect(0, 0, width, height);
        }

        function buildSprites() {
            sprites = [];
            var count = beamCount();
            for (var i = 0; i < count; i++) {
                sprites.push(makeBeamSprite(createBeamLine(i)));
            }
        }

        function beamCount() {
            return Math.max(minBeamCount, Math.min(maxBeamCount, Math.round(width * height / 18000)));
        }

        function createBeamLine(index) {
            var a = seededUnit(index, 11);
            var b = seededUnit(index, 23);
            var c = seededUnit(index, 37);
            var d = seededUnit(index, 53);
            var e = seededUnit(index, 71);

            return {
                x: .04 + a * .92,
                y: .05 + b * .9,
                length: .08 + c * .24,
                angle: -1.28 + d * 2.56,
                speed: .08 + e * .22,
                phase: seededUnit(index, 97) * Math.PI * 2,
                width: 10 + Math.floor(seededUnit(index, 131) * 24),
                alpha: activeTheme.alphaMin + seededUnit(index, 149) * activeTheme.alphaRange,
                color: activeTheme.palette[index % activeTheme.palette.length],
                drift: .35 + seededUnit(index, 167) * .85
            };
        }

        function seededUnit(index, salt) {
            var value = Math.sin((index + 1) * (salt + .137)) * 10000;
            return value - Math.floor(value);
        }

        function makeBeamSprite(line) {
            var span = Math.max(90, Math.min(width, height) * line.length);
            var pad = Math.max(76, line.width * 4.4);
            var spriteWidth = Math.ceil(span + pad * 2);
            var spriteHeight = Math.ceil(pad * 2.05);
            var sprite = document.createElement('canvas');
            var spriteCtx = sprite.getContext && sprite.getContext('2d');

            sprite.width = spriteWidth;
            sprite.height = spriteHeight;
            if (!spriteCtx) {
                return {
                    image: sprite,
                    line: line,
                    halfWidth: spriteWidth * .5,
                    halfHeight: spriteHeight * .5
                };
            }

            spriteCtx.translate(pad, spriteHeight * .5);
            spriteCtx.imageSmoothingEnabled = true;
            spriteStroke(spriteCtx, line, span, line.width * 2.9, .13, 'blur(30px)');
            spriteStroke(spriteCtx, line, span, line.width * 1.7, .16, 'blur(15px)');
            spriteStroke(spriteCtx, line, span, line.width * .65, .12, 'blur(6px)');

            return {
                image: sprite,
                line: line,
                halfWidth: spriteWidth * .5,
                halfHeight: spriteHeight * .5
            };
        }

        function spriteStroke(spriteCtx, line, span, lineWidth, alpha, filter) {
            var glow = spriteCtx.createLinearGradient(0, 0, span, 0);
            glow.addColorStop(0, 'rgba(' + line.color + ', 0)');
            glow.addColorStop(.18, 'rgba(' + line.color + ', ' + alpha + ')');
            glow.addColorStop(.82, 'rgba(' + line.color + ', ' + alpha + ')');
            glow.addColorStop(1, 'rgba(' + line.color + ', 0)');

            spriteCtx.save();
            spriteCtx.filter = filter;
            spriteCtx.globalCompositeOperation = 'lighter';
            spriteCtx.lineCap = 'round';
            spriteCtx.lineJoin = 'round';
            spriteCtx.lineWidth = lineWidth;
            spriteCtx.strokeStyle = glow;
            spriteCtx.beginPath();
            spriteCtx.moveTo(0, 0);
            spriteCtx.lineTo(span, 0);
            spriteCtx.stroke();
            spriteCtx.restore();
        }

        function drawSprites(t) {
            ctx.save();
            ctx.globalCompositeOperation = activeTheme.composite;

            for (var i = 0; i < sprites.length; i++) {
                drawSprite(sprites[i], t);
            }

            ctx.restore();
        }

        function drawSprite(sprite, t) {
            var line = sprite.line;
            var driftX = Math.sin(t * line.speed + line.phase) * Math.min(width * .035, 48) * line.drift;
            var driftY = Math.cos(t * line.speed * .91 + line.phase) * Math.min(height * .045, 42) * line.drift;
            var rotation = line.angle + Math.sin(t * line.speed * .46 + line.phase) * .08;
            var shimmer = .72 + Math.sin(t * line.speed * 1.4 + line.phase) * .18;

            ctx.save();
            ctx.globalAlpha = line.alpha * shimmer;
            ctx.translate(width * line.x + driftX, height * line.y + driftY);
            ctx.rotate(rotation);
            ctx.drawImage(sprite.image, -sprite.halfWidth, -sprite.halfHeight);
            ctx.restore();
        }

        function animate(now) {
            if (now - lastFrame >= frameDelay) {
                lastFrame = now;
                render(now);
            }
            if (!reduceMotion) {
                window.requestAnimationFrame(animate);
            }
        }

        resize();
        window.addEventListener('resize', resize);
        if (window.MutationObserver) {
            new MutationObserver(function () {
                syncTheme(false);
                render(window.performance && window.performance.now ? window.performance.now() : 0);
            }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        }
        if (!reduceMotion) {
            window.requestAnimationFrame(animate);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootModern4Canvas, { once: true });
    } else {
        bootModern4Canvas();
    }
}());
