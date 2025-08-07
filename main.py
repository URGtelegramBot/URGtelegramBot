# -*- coding: utf-8 -*-
import logging
import sqlite3
import random
import re
import os
from telegram import (
    Update,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    KeyboardButton,
    ReplyKeyboardMarkup,
    ChatMember,
    ParseMode
)
from telegram.ext import (
    Updater,
    CommandHandler,
    CallbackQueryHandler,
    MessageHandler,
    Filters,
    ConversationHandler,
    CallbackContext
)

# تنظیمات اولیه
TOKEN = "8289753629:AAGFwY4QVivWrc_zxlpaXLYCWyjX6FN_8m8"
DB_NAME = "membership_bot.db"
logging.basicConfig(
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    level=logging.INFO
)
logger = logging.getLogger(__name__)

# حالت‌های گفتگو
GET_CHANNEL, SELECT_CHANNEL, GET_MEMBER_COUNT = range(3)

# ایجاد پایگاه داده
def init_db():
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    
    cursor.execute('''
    CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY,
        language TEXT DEFAULT 'fa',
        coins REAL DEFAULT 5.0
    )
    ''')
    
    cursor.execute('''
    CREATE TABLE IF NOT EXISTS channels (
        channel_id TEXT PRIMARY KEY,
        title TEXT,
        invite_link TEXT
    )
    ''')
    
    cursor.execute('''
    CREATE TABLE IF NOT EXISTS orders (
        order_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        channel_id TEXT,
        required_users INTEGER,
        current_count INTEGER DEFAULT 0,
        is_active BOOLEAN DEFAULT 1,
        FOREIGN KEY(user_id) REFERENCES users(user_id),
        FOREIGN KEY(channel_id) REFERENCES channels(channel_id)
    )
    ''')
    
    cursor.execute('''
    CREATE TABLE IF NOT EXISTS user_channels (
        user_id INTEGER,
        channel_id TEXT,
        PRIMARY KEY(user_id, channel_id),
        FOREIGN KEY(user_id) REFERENCES users(user_id),
        FOREIGN KEY(channel_id) REFERENCES channels(channel_id)
    )
    ''')
    
    cursor.execute('''
    CREATE TABLE IF NOT EXISTS user_actions (
        user_id INTEGER,
        channel_id TEXT,
        PRIMARY KEY(user_id, channel_id),
        FOREIGN KEY(user_id) REFERENCES users(user_id),
        FOREIGN KEY(channel_id) REFERENCES channels(channel_id)
    )
    ''')
    
    try:
        cursor.execute("PRAGMA table_info(channels)")
        columns = [col[1] for col in cursor.fetchall()]
        if 'invite_link' not in columns:
            cursor.execute("ALTER TABLE channels ADD COLUMN invite_link TEXT")
        
        cursor.execute("PRAGMA table_info(orders)")
        columns = [col[1] for col in cursor.fetchall()]
        if 'is_active' not in columns:
            cursor.execute("ALTER TABLE orders ADD COLUMN is_active BOOLEAN DEFAULT 1")
    except Exception as e:
        logger.error(f"Error adding columns: {str(e)}")
    
    conn.commit()
    conn.close()

# توابع پایگاه داده
def get_user_language(user_id):
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute("SELECT language FROM users WHERE user_id = ?", (user_id,))
    result = cursor.fetchone()
    conn.close()
    return result[0] if result else 'fa'

def get_user_coins(user_id):
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute("SELECT coins FROM users WHERE user_id = ?", (user_id,))
    result = cursor.fetchone()
    conn.close()
    return result[0] if result else 0

def update_user_coins(user_id, amount):
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute("INSERT OR IGNORE INTO users (user_id) VALUES (?)", (user_id,))
    cursor.execute("UPDATE users SET coins = coins + ? WHERE user_id = ?", (amount, user_id))
    conn.commit()
    conn.close()

def add_channel_to_db(user_id, channel_id, title, invite_link):
    try:
        conn = sqlite3.connect(DB_NAME)
        cursor = conn.cursor()
        cursor.execute("INSERT OR IGNORE INTO users (user_id) VALUES (?)", (user_id,))
        cursor.execute(
            "INSERT OR REPLACE INTO channels (channel_id, title, invite_link) VALUES (?, ?, ?)",
            (channel_id, title, invite_link)
        )
        cursor.execute(
            "INSERT OR REPLACE INTO user_channels (user_id, channel_id) VALUES (?, ?)",
            (user_id, channel_id)
        )
        conn.commit()
        return True
    except sqlite3.Error as e:
        logger.error(f"Database error: {str(e)}")
        return False
    finally:
        conn.close()

def delete_channel_from_db(user_id, channel_id):
    try:
        conn = sqlite3.connect(DB_NAME)
        cursor = conn.cursor()
        
        cursor.execute("SELECT 1 FROM user_channels WHERE user_id = ? AND channel_id = ?", (user_id, channel_id))
        if not cursor.fetchone():
            return False, "شما مالک این کانال نیستید!"
        
        cursor.execute('''
        SELECT order_id, required_users, current_count 
        FROM orders 
        WHERE channel_id = ? AND is_active = 1
        ''', (channel_id,))
        active_orders = cursor.fetchall()
        
        refund_amount = 0
        for order_id, required_users, current_count in active_orders:
            refund = required_users - current_count
            refund_amount += refund
            cursor.execute("UPDATE orders SET is_active = 0 WHERE order_id = ?", (order_id,))
        
        if refund_amount > 0:
            update_user_coins(user_id, refund_amount)
        
        cursor.execute("DELETE FROM user_channels WHERE channel_id = ?", (channel_id,))
        cursor.execute("DELETE FROM channels WHERE channel_id = ?", (channel_id,))
        
        conn.commit()
        return True, f"کانال با موفقیت حذف شد! {refund_amount} سکه بازپرداخت شد."
    except sqlite3.Error as e:
        logger.error(f"Delete channel error: {str(e)}")
        return False, "خطا در حذف کانال!"
    finally:
        conn.close()

def get_user_channels(user_id):
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute('''
    SELECT c.channel_id, c.title 
    FROM user_channels uc
    JOIN channels c ON uc.channel_id = c.channel_id
    WHERE uc.user_id = ?
    ''', (user_id,))
    channels = cursor.fetchall()
    conn.close()
    return channels

def create_order(user_id, channel_id, member_count):
    try:
        conn = sqlite3.connect(DB_NAME)
        cursor = conn.cursor()
        cost = member_count
        cursor.execute("UPDATE users SET coins = coins - ? WHERE user_id = ?", (cost, user_id))
        cursor.execute('''
        INSERT INTO orders (user_id, channel_id, required_users)
        VALUES (?, ?, ?)
        ''', (user_id, channel_id, member_count))
        conn.commit()
        return True
    except sqlite3.Error as e:
        logger.error(f"Order creation error: {str(e)}")
        return False
    finally:
        conn.close()

# مدیریت زبان
def get_message(key, lang='fa'):
    messages = {
        'start': {
            'fa': "🌟 به ربات جمع‌آوری عضو خوش آمدید!",
            'en': "🌟 Welcome to the Membership Bot!"
        },
        'add_members': {
            'fa': "➕ اضافه کردن عضو",
            'en': "➕ Add Members"
        },
        'collect_coins': {
            'fa': "💰 جمع‌آوری سکه",
            'en': "💰 Collect Coins"
        },
        'cancel': {
            'fa': "🚪 لغو",
            'en': "🚪 Cancel"
        },
        'no_channels': {
            'fa': "⛔️ شما هیچ کانالی اضافه نکرده‌اید.",
            'en': "⛔️ You haven't added any channels."
        },
        'ask_channel': {
            'fa': "🤖 ربات باید در کانال عضو و ادمین باشد.\nلینک یا آیدی کانال را ارسال کنید:",
            'en': "🤖 The bot must be a member and admin.\nSend channel link or ID:"
        },
        'invalid_format': {
            'fa': "❌ فرمت نامعتبر! از @channel_name یا https://t.me/channel_name استفاده کنید",
            'en': "❌ Invalid format! Use @channel_name or https://t.me/channel_name"
        },
        'not_admin': {
            'fa': "❌ ربات ادمین نیست! لطفاً ربات را به کانال اضافه و ادمین کنید",
            'en': "❌ Bot is not admin! Please add and promote bot in channel"
        },
        'user_not_admin': {
            'fa': "❌ شما ادمین این کانال نیستید! لطفاً ابتدا ادمین شوید",
            'en': "❌ You are not admin of this channel! Please become an admin first"
        },
        'channel_added': {
            'fa': "✅ کانال '{title}' با موفقیت اضافه شد!",
            'en': "✅ Channel '{title}' added successfully!"
        },
        'channel_deleted': {
            'fa': "🗑️ {message}",
            'en': "🗑️ {message}"
        },
        'select_channel': {
            'fa': "📋 لطفاً یک کانال را انتخاب کنید:",
            'en': "📋 Please select a channel:"
        },
        'ask_member_count': {
            'fa': "🔢 تعداد عضو مورد نیاز را وارد کنید (هر عضو ۱ سکه):",
            'en': "🔢 Enter the number of members needed (each member costs 1 coin):"
        },
        'invalid_number': {
            'fa': "❌ عدد نامعتبر! لطفاً یک عدد صحیح وارد کنید.",
            'en': "❌ Invalid number! Please enter a valid integer."
        },
        'not_enough_coins': {
            'fa': "❌ سکه کافی ندارید! موجودی: {coins} سکه",
            'en': "❌ Not enough coins! Balance: {coins} coins"
        },
        'order_created': {
            'fa': "✅ سفارش ثبت شد!\n🔹 کانال: {title}\n🔹 تعداد عضو: {count}\n🔹 هزینه: {cost} سکه",
            'en': "✅ Order created!\n🔹 Channel: {title}\n🔹 Members: {count}\n🔹 Cost: {cost} coins"
        },
        'order_completed': {
            'fa': "🎉 سفارش شما برای کانال '{title}' با موفقیت تکمیل شد!",
            'en': "🎉 Your order for channel '{title}' has been completed!"
        },
        'no_orders': {
            'fa': "⛔️ هیچ سفارش فعالی وجود ندارد",
            'en': "⛔️ No active orders available"
        },
        'order_cancelled': {
            'fa': "🚫 سفارش لغو شد! {refund} سکه بازپرداخت شد.",
            'en': "🚫 Order cancelled! {refund} coins refunded."
        }
    }
    return messages[key][lang]

# دستورات ربات
def start(update: Update, context: CallbackContext) -> None:
    user_id = update.effective_user.id
    lang = get_user_language(user_id)
    
    keyboard = [
        [KeyboardButton("➕ اضافه کردن عضو"), KeyboardButton("💰 جمع‌آوری سکه")],
        [KeyboardButton("🚪 لغو")]
    ]
    reply_markup = ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
    
    update.message.reply_text(
        get_message('start', lang),
        reply_markup=reply_markup
    )
    logger.info(f"User {user_id} started the bot")

def cancel_action(update: Update, context: CallbackContext) -> int:
    user_id = update.effective_user.id
    lang = get_user_language(user_id)
    
    keyboard = [
        [KeyboardButton("➕ اضافه کردن عضو"), KeyboardButton("💰 جمع‌آوری سکه")],
        [KeyboardButton("🚪 لغو")]
    ]
    reply_markup = ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
    
    if update.callback_query:
        update.callback_query.answer()
        update.callback_query.edit_message_text(
            get_message('start', lang),
            reply_markup=reply_markup
        )
    elif update.message:
        update.message.reply_text(
            get_message('start', lang),
            reply_markup=reply_markup
        )
    
    context.user_data.clear()
    logger.info(f"User {user_id} cancelled action")
    return ConversationHandler.END

def add_members(update: Update, context: CallbackContext):
    user_id = update.effective_user.id
    lang = get_user_language(user_id)
    
    logger.info(f"User {user_id} clicked 'Add Members'")
    channels = get_user_channels(user_id)
    
    keyboard = [
        [KeyboardButton("➕ اضافه کردن عضو"), KeyboardButton("💰 جمع‌آوری سکه")],
        [KeyboardButton("🚪 لغو")]
    ]
    reply_markup = ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
    
    if not channels:
        update.message.reply_text(get_message('ask_channel', lang), reply_markup=reply_markup)
        return GET_CHANNEL
    else:
        inline_keyboard = []
        for channel_id, title in channels:
            inline_keyboard.append([
                InlineKeyboardButton(f"📌 {title}", callback_data=f"select_{channel_id}"),
                InlineKeyboardButton("🗑️ حذف", callback_data=f"delete_{channel_id}")
            ])
        
        inline_reply_markup = InlineKeyboardMarkup(inline_keyboard)
        update.message.reply_text(
            get_message('select_channel', lang),
            reply_markup=inline_reply_markup
        )
        context.bot.send_message(
            chat_id=user_id,
            text="لطفاً از کیبورد زیر برای بازگشت استفاده کنید:",
            reply_markup=reply_markup
        )
        return SELECT_CHANNEL

def extract_channel_id(channel_input):
    channel_input = channel_input.strip()
    if re.match(r'^@[a-zA-Z0-9_]{5,32}$', channel_input):
        return channel_input[1:]
    if re.match(r'^(https?://)?(t\.me/|telegram\.me/)[a-zA-Z0-9_]{5,32}/?$', channel_input):
        parts = channel_input.split('/')
        return parts[-1] if parts[-1] != '' else parts[-2]
    return None

def handle_channel_input(update: Update, context: CallbackContext) -> int:
    user_id = update.effective_user.id
    lang = get_user_language(user_id)
    channel_input = update.message.text.strip()
    
    keyboard = [
        [KeyboardButton("➕ اضافه کردن عضو"), KeyboardButton("💰 جمع‌آوری سکه")],
        [KeyboardButton("🚪 لغو")]
    ]
    reply_markup = ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
    
    channel_id = extract_channel_id(channel_input)
    if not channel_id:
        update.message.reply_text(get_message('invalid_format', lang), reply_markup=reply_markup)
        return GET_CHANNEL
    
    logger.info(f"Processing channel: {channel_id} for user: {user_id}")
    
    try:
        try:
            chat = context.bot.get_chat(f"@{channel_id}")
        except:
            try:
                chat = context.bot.get_chat(channel_id)
            except:
                update.message.reply_text(
                    "❌ کانال یافت نشد! لطفاً کانال عمومی و آیدی صحیح وارد کنید."
                    if lang == 'fa' else
                    "❌ Channel not found! Ensure the channel is public and ID is correct.",
                    reply_markup=reply_markup
                )
                return GET_CHANNEL
        
        bot_member = context.bot.get_chat_member(chat.id, context.bot.id)
        if bot_member.status not in [ChatMember.ADMINISTRATOR, ChatMember.CREATOR]:
            update.message.reply_text(get_message('not_admin', lang), reply_markup=reply_markup)
            return GET_CHANNEL
        
        user_member = context.bot.get_chat_member(chat.id, user_id)
        if user_member.status not in [ChatMember.ADMINISTRATOR, ChatMember.CREATOR]:
            update.message.reply_text(get_message('user_not_admin', lang), reply_markup=reply_markup)
            return GET_CHANNEL
        
        title = chat.title if chat.title else f"کانال {channel_id}"
        invite_link = chat.invite_link if hasattr(chat, 'invite_link') and chat.invite_link else f"https://t.me/{channel_id}"
        
        if add_channel_to_db(user_id, channel_id, title, invite_link):
            update.message.reply_text(get_message('channel_added', lang).format(title=title))
            return add_members(update, context)
        else:
            update.message.reply_text(
                "⚠️ خطا در ذخیره‌سازی! دوباره تلاش کنید."
                if lang == 'fa' else
                "⚠️ Storage error! Try again.",
                reply_markup=reply_markup
            )
            return GET_CHANNEL
    except Exception as e:
        logger.error(f"Error in handle_channel_input: {str(e)}")
        update.message.reply_text(
            "⚠️ خطای سیستمی! دوباره تلاش کنید."
            if lang == 'fa' else
            "⚠️ System error! Try again.",
            reply_markup=reply_markup
        )
        return GET_CHANNEL

def select_channel(update: Update, context: CallbackContext) -> int:
    query = update.callback_query
    user_id = query.from_user.id
    lang = get_user_language(user_id)
    
    data = query.data
    if data.startswith('select_'):
        channel_id = data.split('_')[1]
        context.user_data['selected_channel'] = channel_id
        keyboard = [
            [KeyboardButton("➕ اضافه کردن عضو"), KeyboardButton("💰 جمع‌آوری سکه")],
            [KeyboardButton("🚪 لغو")]
        ]
        reply_markup = ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
        query.answer()
        query.edit_message_text(
            get_message('ask_member_count', lang)
        )
        context.bot.send_message(
            chat_id=user_id,
            text="لطفاً از کیبورد زیر برای بازگشت استفاده کنید:",
            reply_markup=reply_markup
        )
        return GET_MEMBER_COUNT
    elif data.startswith('delete_'):
        channel_id = data.split('_')[1]
        success, message = delete_channel_from_db(user_id, channel_id)
        query.answer()
        query.edit_message_text(get_message('channel_deleted', lang).format(message=message))
        return add_members(update, context)

def get_member_count(update: Update, context: CallbackContext) -> int:
    user_id = update.effective_user.id
    lang = get_user_language(user_id)
    text = update.message.text.strip()
    
    keyboard = [
        [KeyboardButton("➕ اضافه کردن عضو"), KeyboardButton("💰 جمع‌آوری سکه")],
        [KeyboardButton("🚪 لغو")]
    ]
    reply_markup = ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
    
    channel_id = context.user_data.get('selected_channel')
    if not channel_id:
        update.message.reply_text(
            "⚠️ خطا در پردازش! دوباره شروع کنید.",
            reply_markup=reply_markup
        )
        return ConversationHandler.END
    
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute("SELECT title FROM channels WHERE channel_id = ?", (channel_id,))
    channel_title = cursor.fetchone()[0]
    conn.close()
    
    try:
        member_count = int(text)
        if member_count <= 0:
            raise ValueError
    except ValueError:
        update.message.reply_text(get_message('invalid_number', lang), reply_markup=reply_markup)
        return GET_MEMBER_COUNT
    
    user_coins = get_user_coins(user_id)
    if user_coins < member_count:
        update.message.reply_text(
            get_message('not_enough_coins', lang).format(coins=user_coins),
            reply_markup=reply_markup
        )
        return ConversationHandler.END
    
    if create_order(user_id, channel_id, member_count):
        update.message.reply_text(
            get_message('order_created', lang).format(
                title=channel_title,
                count=member_count,
                cost=member_count
            ),
            reply_markup=reply_markup
        )
    else:
        update.message.reply_text(
            "⚠️ خطا در ایجاد سفارش! دوباره تلاش کنید."
            if lang == 'fa' else
            "⚠️ Error creating order! Try again.",
            reply_markup=reply_markup
        )
    
    return ConversationHandler.END

def collect_coins(update: Update, context: CallbackContext) -> None:
    user_id = update.effective_user.id
    lang = get_user_language(user_id)
    
    logger.info(f"User {user_id} clicked 'Collect Coins' or continued to next order")
    
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute('''
    SELECT o.order_id, c.channel_id, c.title, c.invite_link, o.user_id, o.required_users, o.current_count
    FROM orders o
    JOIN channels c ON o.channel_id = c.channel_id
    WHERE o.is_active = 1 
    AND o.channel_id NOT IN (
        SELECT ua.channel_id FROM user_actions ua WHERE ua.user_id = ?
    )
    ''', (user_id,))
    available_orders = cursor.fetchall()
    conn.close()
    
    keyboard = [
        [KeyboardButton("➕ اضافه کردن عضو"), KeyboardButton("💰 جمع‌آوری سکه")],
        [KeyboardButton("🚪 لغو")]
    ]
    reply_markup = ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
    
    if not available_orders:
        update.message.reply_text(get_message('no_orders', lang), reply_markup=reply_markup)
        return
    
    order = random.choice(available_orders)
    order_id, channel_id, title, invite_link, owner_id, required_users, current_count = order
    
    context.user_data['current_order'] = {
        'order_id': order_id,
        'channel_id': channel_id,
        'owner_id': owner_id,
        'required_users': required_users,
        'current_count': current_count,
        'invite_link': invite_link
    }
    
    inline_keyboard = [
        [
            InlineKeyboardButton("❌ رد سفارش", callback_data=f"reject_{order_id}"),
            InlineKeyboardButton("✅ تأیید سفارش", callback_data=f"confirm_{order_id}")
        ]
    ]
    inline_reply_markup = InlineKeyboardMarkup(inline_keyboard)
    
    message = (
        f"📢 کانال: {title}\n"
        f"👥 اعضای مورد نیاز: {required_users}\n"
        f"🔗 لینک عضویت: {invite_link}\n\n"
        "پس از عضویت در کانال، روی دکمه 'تأیید سفارش' کلیک کنید"
        if lang == 'fa' else
        f"📢 Channel: {title}\n"
        f"👥 Members needed: {required_users}\n"
        f"🔗 Join link: {invite_link}\n\n"
        "After joining the channel, click the 'Confirm Order' button"
    )
    
    update.message.reply_text(message, reply_markup=inline_reply_markup)
    context.bot.send_message(
        chat_id=user_id,
        text="لطفاً از کیبورد زیر برای بازگشت استفاده کنید:",
        reply_markup=reply_markup
    )

def button_click(update: Update, context: CallbackContext) -> None:
    query = update.callback_query
    data = query.data
    user_id = query.from_user.id
    lang = get_user_language(user_id)
    
    query.answer()
    
    keyboard = [
        [KeyboardButton("➕ اضافه کردن عضو"), KeyboardButton("💰 جمع‌آوری سکه")],
        [KeyboardButton("🚪 لغو")]
    ]
    reply_markup = ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
    
    if data.startswith('confirm_'):
        order_id = data.split('_')[1]
        order_data = context.user_data.get('current_order', {})
        
        if not order_data or str(order_data['order_id']) != order_id:
            query.edit_message_text(
                "⚠️ خطا در پردازش سفارش! دوباره تلاش کنید.",
                reply_markup=reply_markup
            )
            context.bot.send_message(
                chat_id=user_id,
                text="لطفاً از کیبورد زیر برای بازگشت استفاده کنید:",
                reply_markup=reply_markup
            )
            return
            
        channel_id = order_data['channel_id']
        owner_id = order_data['owner_id']
        required_users = order_data['required_users']
        current_count = order_data['current_count']
        invite_link = order_data['invite_link']
        
        try:
            bot_member = context.bot.get_chat_member(chat_id=f"@{channel_id}", user_id=context.bot.id)
            if bot_member.status not in [ChatMember.ADMINISTRATOR, ChatMember.CREATOR]:
                raise Exception("Bot is not admin")
        except Exception as e:
            logger.error(f"Admin check failed: {str(e)}")
            
            refund_amount = required_users - current_count
            update_user_coins(owner_id, refund_amount)
            
            conn = sqlite3.connect(DB_NAME)
            cursor = conn.cursor()
            cursor.execute("UPDATE orders SET is_active = 0 WHERE order_id = ?", (order_id,))
            conn.commit()
            conn.close()
            
            try:
                context.bot.send_message(
                    owner_id,
                    get_message('order_cancelled', lang).format(refund=refund_amount)
                )
            except:
                logger.error("Failed to notify order owner")
            
            query.edit_message_text(
                "🚫 سفارش به دلیل عدم دسترسی ربات لغو شد."
                if lang == 'fa' else
                "🚫 Order cancelled due to bot access issues.",
                reply_markup=reply_markup
            )
            context.bot.send_message(
                chat_id=user_id,
                text="لطفاً از کیبورد زیر برای بازگشت استفاده کنید:",
                reply_markup=reply_markup
            )
            return
        
        try:
            chat_member = context.bot.get_chat_member(chat_id=f"@{channel_id}", user_id=user_id)
            is_member = chat_member.status in [ChatMember.MEMBER, ChatMember.ADMINISTRATOR, ChatMember.CREATOR]
        except Exception as e:
            logger.error(f"Membership check failed: {str(e)}")
            is_member = False
        
        if is_member:
            update_user_coins(user_id, 0.5)
            
            conn = sqlite3.connect(DB_NAME)
            cursor = conn.cursor()
            cursor.execute(
                "INSERT OR IGNORE INTO user_actions (user_id, channel_id) VALUES (?, ?)", 
                (user_id, channel_id)
            )
            
            new_count = current_count + 1
            cursor.execute(
                "UPDATE orders SET current_count = ? WHERE order_id = ?", 
                (new_count, order_id)
            )
            
            if new_count >= required_users:
                cursor.execute(
                    "UPDATE orders SET is_active = 0 WHERE order_id = ?", 
                    (order_id,)
                )
                cursor.execute("SELECT title FROM channels WHERE channel_id = ?", (channel_id,))
                channel_title = cursor.fetchone()[0]
                try:
                    context.bot.send_message(
                        owner_id,
                        get_message('order_completed', lang).format(title=channel_title)
                    )
                except:
                    logger.error("Failed to notify order owner about completion")
            
            conn.commit()
            conn.close()
            
            query.edit_message_text(
                "✅ عضویت تأیید شد!\n➕ 0.5 سکه اضافه شد"
                if lang == 'fa' else
                "✅ Membership confirmed!\n➕ 0.5 coins added"
            )
            # نمایش خودکار کانال بعدی
            collect_coins(update, context)
        else:
            inline_keyboard = [
                [
                    InlineKeyboardButton("❌ رد سفارش", callback_data=f"reject_{order_id}"),
                    InlineKeyboardButton("✅ تأیید سفارش", callback_data=f"confirm_{order_id}")
                ]
            ]
            inline_reply_markup = InlineKeyboardMarkup(inline_keyboard)
            query.edit_message_text(
                f"❌ شما در کانال عضو نیستید!\n\nلطفاً ابتدا عضو شوید:\n{invite_link}\n\nسپس 'تأیید سفارش' را بزنید"
                if lang == 'fa' else
                f"❌ You haven't joined the channel!\n\nPlease join first:\n{invite_link}\n\nThen click 'Confirm Order'",
                reply_markup=inline_reply_markup
            )
            context.bot.send_message(
                chat_id=user_id,
                text="لطفاً از کیبورد زیر برای بازگشت استفاده کنید:",
                reply_markup=reply_markup
            )
        
    elif data.startswith('reject_'):
        query.edit_message_text(
            "🚫 سفارش رد شد."
            if lang == 'fa' else
            "🚫 Order rejected."
        )
        # نمایش خودکار کانال بعدی
        collect_coins(update, context)

def error_handler(update: Update, context: CallbackContext):
    logger.error(msg="Exception while handling update:", exc_info=context.error)
    if update and update.message:
        lang = get_user_language(update.message.from_user.id)
        keyboard = [
            [KeyboardButton("➕ اضافه کردن عضو"), KeyboardButton("💰 جمع‌آوری سکه")],
            [KeyboardButton("🚪 لغو")]
        ]
        reply_markup = ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
        update.message.reply_text(
            "⚠️ خطایی رخ داد! دوباره تلاش کنید."
            if lang == 'fa' else
            "⚠️ An error occurred! Try again.",
            reply_markup=reply_markup
        )

def cancel_handler(update: Update, context: CallbackContext) -> None:
    user_id = update.effective_user.id
    lang = get_user_language(user_id)
    
    keyboard = [
        [KeyboardButton("➕ اضافه کردن عضو"), KeyboardButton("💰 جمع‌آوری سکه")],
        [KeyboardButton("🚪 لغو")]
    ]
    reply_markup = ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
    
    update.message.reply_text(
        get_message('start', lang),
        reply_markup=reply_markup
    )
    context.user_data.clear()
    logger.info(f"User {user_id} clicked 'Cancel'")

def main() -> None:
    init_db()
    updater = Updater(TOKEN, use_context=True)
    dispatcher = updater.dispatcher

    dispatcher.add_handler(CommandHandler("start", start))
    
    conv_handler = ConversationHandler(
        entry_points=[MessageHandler(Filters.regex(r'^➕ اضافه کردن عضو$'), add_members)],
        states={
            GET_CHANNEL: [
                MessageHandler(Filters.text & ~Filters.command, handle_channel_input)
            ],
            SELECT_CHANNEL: [CallbackQueryHandler(select_channel)],
            GET_MEMBER_COUNT: [
                MessageHandler(Filters.text & ~Filters.command, get_member_count)
            ]
        },
        fallbacks=[MessageHandler(Filters.regex(r'^🚪 لغو$'), cancel_handler)],
        allow_reentry=True
    )
    dispatcher.add_handler(conv_handler)
    
    dispatcher.add_handler(MessageHandler(Filters.regex(r'^💰 جمع‌آوری سکه$'), collect_coins))
    dispatcher.add_handler(MessageHandler(Filters.regex(r'^🚪 لغو$'), cancel_handler))
    dispatcher.add_handler(CallbackQueryHandler(button_click))
    dispatcher.add_error_handler(error_handler)

    updater.start_polling()
    logger.info("Bot started and polling...")
    updater.idle()

if __name__ == '__main__':
    main()
