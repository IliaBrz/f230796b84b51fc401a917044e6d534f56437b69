-- Тестовые данные для "Кулинарная книга \"Кривые ручки\""
USE crooked_hands_cookbook;

-- Пользователи (по ТЗ пароль хранится в plain text)
INSERT INTO users (last_name, first_name, email, password, role, registered_at, last_login_at) VALUES
('Админов', 'Админ', 'admin', '20262026', 'admin', NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 1 HOUR),
('Иванов', 'Иван', 'user1@example.com', '12345', 'user', NOW() - INTERVAL 15 DAY, NOW() - INTERVAL 2 DAY),
('Поваров', 'Шеф', 'chef@example.com', 'qwerty', 'user', NOW() - INTERVAL 10 DAY, NULL);

-- Статьи (BBCode в body)
INSERT INTO posts (author_id, title, body, created_at, updated_at) VALUES
(
  1,
  'Яйца всмятку без пожара',
  '[b]Цель:[/b] сварить яйца и не сжечь соседей.\n\n[i]Шаги:[/i]\n1) Налейте воду.\n2) Доведите до кипения.\n3) Аккуратно опустите яйца.\n\n[color=#e53935]Совет:[/color] поставьте таймер.\n\n[img]https://images.unsplash.com/photo-1541592106381-b31e9677c0e5?auto=format&fit=crop&w=1200&q=60[/img]',
  NOW() - INTERVAL 9 DAY,
  NOW() - INTERVAL 2 DAY
),
(
  2,
  'Макароны уровня “почти Италия”',
  'Берём макароны, воду и чуточку смелости.\n\n[b]Ингредиенты:[/b]\n- макароны\n- соль\n- масло\n\n[u]Важно:[/u] не переварить.',
  NOW() - INTERVAL 7 DAY,
  NOW() - INTERVAL 7 DAY
),
(
  3,
  'Салат “Кривые ручки”',
  'Смешайте всё что есть в холодильнике.\n\n[color=green]Если получилось вкусно — это успех.[/color]\nЕсли нет — назовите это “авторской интерпретацией”.',
  NOW() - INTERVAL 4 DAY,
  NOW() - INTERVAL 1 DAY
);

-- Комментарии
INSERT INTO comments (post_id, user_id, body, created_at) VALUES
(1, 2, 'С таймером реально проще. Спасибо!', NOW() - INTERVAL 1 DAY),
(1, 3, 'Я сварил и ничего не подгорело. Я кулинар!', NOW() - INTERVAL 10 HOUR),
(2, 2, 'Добавил чеснок — стало лучше.', NOW() - INTERVAL 2 DAY);

-- Сообщения контактной формы
INSERT INTO contact_messages (name, email, message, created_at, read_at) VALUES
('Ирина', 'irina@example.com', 'Здравствуйте! Добавьте, пожалуйста, рецепт борща.', NOW() - INTERVAL 3 DAY, NULL),
('Павел', 'pavel@example.com', 'Нашёл опечатку в одном рецепте. Куда писать?', NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 1 DAY);

