#include <stdlib.h>
#include <string.h>
#include <stdio.h>
#include <stdint.h>
#include <stdbool.h>

char* convert_to_html_entities(const char* input) {
    if (input == NULL) {
        return strdup(""); // Возвращаем пустую строку
    }

    size_t len = strlen(input);
    size_t result_size = len * 9; // Заранее выделяем достаточно памяти для worst-case. 9 - максимум длины HTML-сущности

    // Выделяем память для результата
    char* result = (char*)malloc(result_size);
    if (!result) {
        return NULL; // Ошибка выделения памяти
    }

    size_t pos = 0; // Позиция записи в результирующую строку
    for (size_t i = 0; i < len;) {
        unsigned char c = input[i];
        uint32_t codepoint = 0;
        int char_len = 1;
        bool valid_char = true;

        // Определяем длину и кодовую точку для UTF-8 символа
        if ((c & 0x80) == 0) {
            char_len = 1;
            codepoint = c;
        } else if ((c & 0xE0) == 0xC0 && i + 1 < len) {
            char_len = 2;
            codepoint = ((input[i] & 0x1F) << 6) | (input[i + 1] & 0x3F);
        } else if ((c & 0xF0) == 0xE0 && i + 2 < len) {
            char_len = 3;
            codepoint = ((input[i] & 0x0F) << 12) | ((input[i + 1] & 0x3F) << 6) | (input[i + 2] & 0x3F);
        } else if ((c & 0xF8) == 0xF0 && i + 3 < len) {
            char_len = 4;
            codepoint = ((input[i] & 0x07) << 18) | ((input[i + 1] & 0x3F) << 12) |
                        ((input[i + 2] & 0x3F) << 6) | (input[i + 3] & 0x3F);
        } else {
            valid_char = false;
        }

        // Обработка символа
        if (valid_char) {
            // Для большинства символов конвертируем в HTML-сущности
            switch (codepoint) {
                case '<':  pos += snprintf(result + pos, result_size - pos, "&lt;"); break;
                case '>':  pos += snprintf(result + pos, result_size - pos, "&gt;"); break;
                case '&':  pos += snprintf(result + pos, result_size - pos, "&amp;"); break;
                case '"':  pos += snprintf(result + pos, result_size - pos, "&quot;"); break;
                case '\'': pos += snprintf(result + pos, result_size - pos, "&#39;"); break;
                default:
                    // Если символ внутри BMP (U+0000..U+FFFF), оставляем как есть
                    if (codepoint <= 0xFFFF) {
                         memcpy(result + pos, input + i, char_len);
                         pos += char_len;
                    } else {
                        // Для символов за пределами BMP (эмодзи и т.д.) — используем числовую HTML-сущность
                        pos += snprintf(result + pos, result_size - pos, "&#%u;", codepoint);
                    }
                    break;
            }
        } else {
            // Если символ некорректный, пропускаем его
            i++;
            continue;
        }

        i += char_len; // Переход к следующему символу
    }

    result[pos] = '\0'; // Завершаем строку

    return result;
}

void free_converted_string(char* result) {
    if (result) {
        free(result);
    }
}