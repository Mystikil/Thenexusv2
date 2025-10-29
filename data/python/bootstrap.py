print("[Python] bootstrap loaded")


def on_server_start():
    print("[Python] on_server_start")


def on_server_stop():
    print("[Python] on_server_stop")


def on_player_login(name: str):
    print(f"[Python] on_player_login: {name}")


def on_player_logout(name: str):
    print(f"[Python] on_player_logout: {name}")


def on_creature_death(killer: str, victim: str):
    print(f"[Python] on_creature_death: {killer} killed {victim}")


def some_func(*args):
    print("[Python] some_func args:", args)
