# ui_components.py
import tkinter as tk
from tkinter import messagebox, ttk, simpledialog
import config
from game_logic import BeautyContestLogic
import random

class BeautyContestUI(tk.Tk):
    def __init__(self):
        super().__init__()
        self.withdraw()

        # 1. Pilih Mode
        is_single_player = messagebox.askyesno("Pilih Mode", "Apakah Anda ingin bermain Mode Single Player (Lawan Bot)?\n\nYes = Lawan Bot\nNo = Multiplayer (Main Bergantian)")

        # 2. Pilih Jumlah Pemain
        msg_prompt = "Pilih total pemain (termasuk Anda):" if is_single_player else "Pilih jumlah pemain:"
        num_players = self.ask_player_count_dropdown("Setup Permainan", msg_prompt)

        if not num_players:
            self.destroy()
            return

        # 3. INPUT NAMA PEMAIN
        player_names = []
        if is_single_player:
            # Single Player: Tanya nama Player 1 saja
            name = simpledialog.askstring("Nama Pemain", "Masukkan Nama Anda:")
            if not name or name.strip() == "":
                name = "Player 1"
            player_names.append(name)
        else:
            # Multiplayer: Tanya nama semua pemain
            for i in range(num_players):
                name = simpledialog.askstring("Nama Pemain", f"Masukkan Nama Player {i+1}:")
                if not name or name.strip() == "":
                    name = f"Player {i+1}"
                player_names.append(name)

        self.deiconify()

        # Kirim nama ke Logic
        self.logic = BeautyContestLogic(num_players, single_player_mode=is_single_player, player_names=player_names)

        self.can_play = True
        self.current_round_results = None

        self.setup_window()
        self.setup_styles()
        self.create_layout()
        self.update_ui_state()

    def ask_player_count_dropdown(self, title, prompt):
        dialog = tk.Toplevel(self)
        dialog.title(title)
        w, h = 350, 180
        sw = self.winfo_screenwidth()
        sh = self.winfo_screenheight()
        x = (sw - w) // 2
        y = (sh - h) // 2
        dialog.geometry(f"{w}x{h}+{x}+{y}")
        dialog.resizable(False, False)

        result = [None]

        def on_confirm():
            if combo.get():
                result[0] = int(combo.get())
                dialog.destroy()

        frame = tk.Frame(dialog, padx=20, pady=20)
        frame.pack(fill=tk.BOTH, expand=True)

        tk.Label(frame, text=prompt, font=config.FONT_NORMAL, wraplength=300).pack(pady=(0, 15))

        values = list(range(2, 11))
        combo = ttk.Combobox(frame, values=values, state="readonly", font=config.FONT_BIG, width=5, justify="center")
        combo.set(4)
        combo.pack(pady=(0, 20))

        btn = ttk.Button(frame, text="Lanjut", command=on_confirm)
        btn.pack(ipadx=10, ipady=5)

        dialog.lift()
        dialog.focus_force()
        dialog.grab_set()
        self.wait_window(dialog)

        return result[0]

    def setup_window(self):
        self.title(config.TITLE)
        sw = self.winfo_screenwidth()
        sh = self.winfo_screenheight()
        x = (sw - config.WINDOW_WIDTH) // 2
        y = (sh - config.WINDOW_HEIGHT) // 2
        self.geometry(f"{config.WINDOW_WIDTH}x{config.WINDOW_HEIGHT}+{x}+{y}")
        self.resizable(True, True) # Fitur teman: Resizable

    def setup_styles(self):
        style = ttk.Style()
        style.theme_use('clam')
        style.configure("Treeview.Heading", font=config.FONT_BOLD, background="#ddd")
        style.configure("Treeview", font=config.FONT_NORMAL, rowheight=25)

    def create_layout(self):
        # Menggunakan width=800 sesuai edit teman Anda
        self.frame_left = tk.Frame(self, width=800, bg=config.COLOR_BG_MAIN)
        self.frame_left.pack(side=tk.LEFT, fill=tk.BOTH, expand=True, padx=10, pady=10)

        self.lbl_turn_info = tk.Label(self.frame_left, text="Menunggu...",
                                      font=config.FONT_HEADER, bg=config.COLOR_BG_MAIN)
        self.lbl_turn_info.pack(pady=(0, 5))

        self.lbl_input_display = tk.Label(self.frame_left, text="?",
                                          font=config.FONT_BIG, bg="white", relief="sunken", width=5)
        self.lbl_input_display.pack(pady=(0, 10))

        self.frame_grid = tk.Frame(self.frame_left, bg=config.COLOR_BG_MAIN)
        self.frame_grid.pack()
        self.create_buttons_compact()

        self.lbl_rules = tk.Label(self.frame_left, text="", font=config.FONT_RULES,
                                  bg=config.COLOR_BG_MAIN, fg=config.COLOR_WARNING, justify="left")
        self.lbl_rules.pack(pady=(20,0), anchor="w")

        self.frame_right = tk.Frame(self, width=320, bg="white", relief="groove", bd=1)
        self.frame_right.pack(side=tk.RIGHT, fill=tk.BOTH, padx=10, pady=10)

        tk.Label(self.frame_right, text="KLASEMEN (SCOREBOARD)", font=config.FONT_BOLD, bg="white").pack(pady=(10,5))

        frame_table = tk.Frame(self.frame_right)
        frame_table.pack(fill=tk.X, padx=5)

        # Update Kolom: Nama Pemain jadi prioritas
        columns = ("name", "role", "score")
        self.tree_score = ttk.Treeview(frame_table, columns=columns, show="headings", height=8)

        self.tree_score.heading("name", text="Nama Pemain")
        self.tree_score.column("name", width=100, anchor="w") # Align left biar nama panjang muat

        self.tree_score.heading("role", text="Peran")
        self.tree_score.column("role", width=60, anchor="center")

        self.tree_score.heading("score", text="Poin")
        self.tree_score.column("score", width=50, anchor="center")

        scrollbar = ttk.Scrollbar(frame_table, orient=tk.VERTICAL, command=self.tree_score.yview)
        self.tree_score.configure(yscroll=scrollbar.set)

        self.tree_score.pack(side=tk.LEFT, fill=tk.X, expand=True)
        scrollbar.pack(side=tk.RIGHT, fill=tk.Y)

        self.tree_score.tag_configure('active_turn', background='#fff9c4')
        self.tree_score.tag_configure('eliminated', background='#ffcdd2', foreground='red')
        self.tree_score.tag_configure('normal', background='white')

        tk.Label(self.frame_right, text="LOG PERMAINAN", font=config.FONT_BOLD, bg="white").pack(pady=(15,5))

        self.text_log = tk.Text(self.frame_right, font=config.FONT_MONO, height=12, width=35,
                                state="disabled", bg="#f8f9fa", relief="flat", padx=5, pady=5)

        log_scroll = ttk.Scrollbar(self.frame_right, orient=tk.VERTICAL, command=self.text_log.yview)
        self.text_log.configure(yscroll=log_scroll.set)

        self.text_log.pack(side=tk.LEFT, fill=tk.BOTH, expand=True, padx=(5,0), pady=5)
        log_scroll.pack(side=tk.RIGHT, fill=tk.Y, pady=5, padx=(0,5))

    def create_buttons_compact(self):
        # Menggunakan ukuran tombol besar (8x4) sesuai edit teman Anda
        btn_0 = tk.Button(self.frame_grid, text="0", font=config.FONT_NORMAL, width=8, height=4,
                          command=lambda: self.on_button_click("0"))
        btn_0.grid(row=10, column=9, padx=1, pady=1)

        for i in range(10):
            for j in range(10):
                val = (i * 10) + (j + 1)
                btn = tk.Button(self.frame_grid, text=str(val), font=config.FONT_NORMAL, width=8, height=4,
                                command=lambda v=str(val): self.on_button_click(v))
                btn.grid(row=i, column=j, padx=1, pady=1)

    def update_active_rules_display(self):
        count = self.logic.get_active_player_count()
        rules_text = f"Pemain Aktif: {count}\nAturan Aktif:"
        if count <= 4: rules_text += "\n[!] Penalti Duplikasi: Angka sama tidak valid!"
        if count <= 3: rules_text += "\n[!] Serangan Kritis: Tebakan tepat mengurangi 2 poin!"
        if count == 2: rules_text += "\n[!] Duel: Jika 0 vs 100, 100 menang!"
        self.lbl_rules.config(text=rules_text)

    def update_ui_state(self):
        current_p = self.logic.get_current_player()
        current_p_id = current_p.id if current_p else -1

        # Update Info Giliran Menggunakan NAMA
        if current_p_id != -1:
            turn_text = f"Giliran {current_p.name}"
            if current_p.is_bot:
                self.lbl_turn_info.config(text=f"{current_p.name} berpikir...", fg="blue")
                self.lbl_input_display.config(text="...")
                for widget in self.frame_grid.winfo_children():
                    widget.config(state="disabled")
                self.after(1000, self.run_bot_turn)
            else:
                self.lbl_turn_info.config(text=turn_text, fg="black")
                self.lbl_input_display.config(text="?")
                for widget in self.frame_grid.winfo_children():
                    widget.config(state="normal")

        self.update_active_rules_display()

        # Update Scoreboard dengan NAMA
        for item in self.tree_score.get_children():
            self.tree_score.delete(item)

        for p in self.logic.players:
            role = "Bot" if p.is_bot else "Human"
            score_display = "ELIM" if p.is_eliminated else str(p.score)

            row_tag = 'normal'
            if p.is_eliminated:
                row_tag = 'eliminated'
            elif p.id == current_p_id:
                row_tag = 'active_turn'

            self.tree_score.insert("", "end", values=(p.name, role, score_display), tags=(row_tag,))

    def run_bot_turn(self):
        if not self.can_play: return
        bot_choice = str(random.randint(0, 100))
        self.on_button_click(bot_choice)

    def on_button_click(self, value):
        if not self.can_play: return
        self.lbl_input_display.config(text="*")
        has_next = self.logic.set_choice(value)

        if has_next:
            self.after(200, self.update_ui_state)
        else:
            self.can_play = False
            self.lbl_turn_info.config(text="Menghitung...", fg="black")
            self.start_animation()

    def log(self, text, color=None, bold=False):
        self.text_log.config(state="normal")
        if text.startswith("---"):
             self.text_log.insert(tk.END, "\n" + "="*30 + "\n", "separator")

        self.text_log.insert(tk.END, text + "\n")

        last_line_idx = int(self.text_log.index('end-1c').split('.')[0]) - 1
        tag_name = f"tag_{last_line_idx}"
        self.text_log.tag_add(tag_name, f"{last_line_idx}.0", f"{last_line_idx}.end")

        if color:
            self.text_log.tag_config(tag_name, foreground=color)
        if bold:
            self.text_log.tag_config(tag_name, font=config.FONT_BOLD)

        self.text_log.tag_config("separator", foreground="#aaa")

        self.text_log.see(tk.END)
        self.text_log.config(state="disabled")

    def start_animation(self):
        self.current_round_results = self.logic.calculate_round_results()
        self.after(500, lambda: self.animate_step(0))

    def animate_step(self, step):
        res = self.current_round_results
        delay = 1200

        if step == 0:
            self.log("--- HASIL RONDE ---", bold=True)

            participants = res['winners'] + res['losers']
            participants.sort(key=lambda p: p.id)

            # Format tabel log menggunakan NAMA
            self.log(f"{'Nama':<10} | {'Angka':<5}")
            self.log("-" * 18)
            for p in participants:
                display_name = (p.name[:9] + '..') if len(p.name) > 9 else p.name
                self.log(f"{display_name:<10} | {int(p.last_choice)}")

            self.after(delay, lambda: self.animate_step(1))

        elif step == 1:
            # Menggunakan 2 Desimal
            self.log(f"\nRata-rata : {res['avg']:.2f}")
            self.after(delay, lambda: self.animate_step(2))

        elif step == 2:
            # Menggunakan 2 Desimal
            self.log(f"Target (x0.8): {res['target']:.2f}", bold=True)
            if res['logs']:
                for l in res['logs']:
                    self.log(f">> {l}", color="red")
            self.after(delay, lambda: self.animate_step(3))

        elif step == 3:
            if not res['winners']:
                self.log("Tidak ada Pemenang!", color="blue", bold=True)
            else:
                winner_names = [p.name for p in res['winners']]
                self.log(f"WINNER: {', '.join(winner_names)}", color="green", bold=True)

            for p in res['losers']:
                if p.is_eliminated and p.score == config.ELIMINATION_THRESHOLD:
                    self.log(f"-> {p.name} TERELIMINASI!", color="red", bold=True)

            self.update_ui_state()
            self.after(delay, lambda: self.animate_step(4))

        elif step == 4:
            match_winner = self.logic.get_match_winner()
            if match_winner:
                win_msg = f"Selamat!\n{match_winner.name} Menang!"
                if match_winner.is_bot:
                     win_msg = f"Game Over!\n{match_winner.name} Memenangkan Pertandingan."

                messagebox.showinfo("GAME OVER", win_msg)
                self.destroy()
            else:
                self.logic.reset_round()
                self.can_play = True
                self.update_ui_state()