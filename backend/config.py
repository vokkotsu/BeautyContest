# config.py
# Pengaturan tampilan dan aturan permainan

# --- KONFIGURASI UMUM ---
TITLE = "Beauty Contest (Strategic Mode)"

# --- ATURAN GAME ---
STARTING_SCORE = 0
LOSS_PENALTY = 1
CRITICAL_PENALTY = 2       # Aturan baru (3 pemain atau kurang)
ELIMINATION_THRESHOLD = -10

# --- TAMPILAN ---
BUTTON_SIZE = 35
WINDOW_WIDTH = 750 
WINDOW_HEIGHT = 600 # Sedikit lebih tinggi untuk muat info rules

# Font
FONT_HEADER = ("Helvetica", 16, "bold")
FONT_BIG = ("Helvetica", 20, "bold")
FONT_NORMAL = ("Helvetica", 10)
FONT_BOLD = ("Helvetica", 10, "bold")
FONT_MONO = ("Consolas", 10)
FONT_RULES = ("Helvetica", 9, "italic") # Font untuk info rules

# Warna
COLOR_BG_MAIN = "#f0f0f0"
COLOR_BTN_DEFAULT = "#e0e0e0"
COLOR_BTN_ACTIVE = "#4caf50"
COLOR_TEXT_ELIMINATED = "#ff0000"
COLOR_WARNING = "#d32f2f" # Merah tua untuk peringatan rules