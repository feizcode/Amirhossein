import Alpine from '@alpinejs/csp';
import {
  createIcons,
  NotebookPen,Library,Sprout,MessageCircleQuestion,LayoutDashboard,
  ChartNoAxesCombined,DatabaseBackup,Calculator,BookOpen,FlaskConical,
  Globe,Sun,BookMarked,Plus,Check,RotateCcw,ArrowRight,ArrowUpLeft,
  BookPlus,Compass,Flag,Lightbulb,Sparkles,Telescope,
  Flame,Moon,Gamepad2,Trophy,Zap,Smile,Rocket,Award,CheckCircle2,Heart,Calendar,TrendingUp,Clock,
  CalendarDays,ArrowLeft,Backpack,Sunset,Pencil
} from 'lucide';
import '@fontsource/vazirmatn/400.css';
import '@fontsource/vazirmatn/500.css';
import '@fontsource/vazirmatn/700.css';
import './design.css';
import './app.css';
import './practice.css';
import {startJournal} from './journal.js';

const icons = {
  NotebookPen,Library,Sprout,MessageCircleQuestion,LayoutDashboard,
  ChartNoAxesCombined,DatabaseBackup,Calculator,BookOpen,FlaskConical,
  Globe,Sun,BookMarked,Plus,Check,RotateCcw,ArrowRight,ArrowUpLeft,
  BookPlus,Compass,Flag,Lightbulb,Sparkles,Telescope,
  Flame,Moon,Gamepad2,Trophy,Zap,Smile,Rocket,Award,CheckCircle2,Heart,Calendar,TrendingUp,Clock,
  CalendarDays,ArrowLeft,Backpack,Sunset,Pencil
};

window.lucide = {createIcons: options => createIcons({icons, ...options})};

window.shootConfetti = function() {
  try {
    const canvas = document.createElement('canvas');
    canvas.className = 'ks-confetti-canvas';
    canvas.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;pointer-events:none;z-index:999999;';
    document.body.appendChild(canvas);
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    const ctx = canvas.getContext('2d');
    const colors = ['#6366f1', '#10b981', '#f59e0b', '#ec4899', '#38bdf8', '#a855f7', '#fbbf24'];
    const particles = Array.from({length: 42}, () => ({
      x: canvas.width / 2 + (Math.random() - 0.5) * 240,
      y: canvas.height * 0.45 + (Math.random() - 0.5) * 100,
      vx: (Math.random() - 0.5) * 14,
      vy: Math.random() * -12 - 4,
      size: Math.random() * 8 + 4,
      color: colors[Math.floor(Math.random() * colors.length)],
      rot: Math.random() * 360,
      vrot: (Math.random() - 0.5) * 12,
      life: 1
    }));
    let frame;
    function update() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      let alive = false;
      for (const p of particles) {
        p.x += p.vx;
        p.y += p.vy;
        p.vy += 0.42;
        p.rot += p.vrot;
        p.life -= 0.018;
        if (p.life > 0) {
          alive = true;
          ctx.save();
          ctx.translate(p.x, p.y);
          ctx.rotate((p.rot * Math.PI) / 180);
          ctx.fillStyle = p.color;
          ctx.globalAlpha = Math.max(0, p.life);
          ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.65);
          ctx.restore();
        }
      }
      if (alive) {
        frame = requestAnimationFrame(update);
      } else {
        cancelAnimationFrame(frame);
        canvas.remove();
      }
    }
    frame = requestAnimationFrame(update);
  } catch {}
};

Alpine.data('journal', () => ({
  busy: true,
  error: '',
  init() {
    startJournal(this.$root, {busy: value => this.busy = value}).catch(e => {
      this.error = e.message;
      this.busy = false;
    });
  }
}));

Alpine.start();
